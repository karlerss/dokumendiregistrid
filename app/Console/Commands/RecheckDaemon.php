<?php

namespace App\Console\Commands;

use App\Lib\Recheck\DocumentChecker;
use App\Lib\Recheck\Heartbeat;
use App\Lib\Recheck\HostThrottle;
use App\Lib\Recheck\RemoteCheck;
use App\Mail\RecheckDigestMail;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\Organisation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RecheckDaemon extends Command
{
    protected $signature = 'app:recheck-daemon
        {--once : Process everything that is due, then exit}
        {--limit= : Stop after this many checks}
        {--document=* : Only these document ids (implies --once)}
        {--org= : Only documents of this organisation id}
        {--dry-run : Probe the registries but write nothing}';

    protected $description = 'Continuously re-check public documents against their source registries and record restriction or availability changes';

    private bool $stopRequested = false;
    private int $checked = 0;

    private DocumentChecker $checker;
    private HostThrottle $throttle;
    private Heartbeat $heartbeat;

    /** @var array<int, string> organisation id => host */
    private array $orgHosts = [];

    public function handle(): int
    {
        $this->checker = DocumentChecker::fromConfig();
        $this->throttle = HostThrottle::fromConfig();
        $this->heartbeat = new Heartbeat();
        $this->installSignalHandlers();

        $once = $this->option('once') || !empty($this->option('document'));
        $limit = $this->option('limit') !== null ? (int)$this->option('limit') : null;
        $dryRun = (bool)$this->option('dry-run');
        $batchSize = (int)config('recheck.batch_size', 200);

        $this->loadOrgHosts();

        if (!$dryRun) {
            $created = DocumentRemoteState::reconcile();
            if ($created > 0) {
                $this->info("Created $created missing state rows.");
            }
        }

        $lastReconcile = now();

        while (!$this->stopRequested) {
            if (!$dryRun && $lastReconcile->lt(now()->subHour())) {
                DocumentRemoteState::reconcile();
                $this->loadOrgHosts();
                $lastReconcile = now();
            }

            $batch = $this->nextBatch($batchSize);

            if ($batch->isEmpty()) {
                if (!$dryRun) {
                    $this->heartbeat->flush($this->throttle->pausedHosts());
                }
                if ($once) {
                    break;
                }
                $this->maybeSendDigest();
                $this->idle((int)config('recheck.idle_sleep_seconds', 60));
                continue;
            }

            $this->processBatch($batch, $dryRun, $limit);

            if (!$dryRun) {
                $this->heartbeat->flush($this->throttle->pausedHosts());
                $this->maybeSendDigest();
            }

            if ($limit !== null && $this->checked >= $limit) {
                break;
            }

            gc_collect_cycles();
        }

        $this->info("Done. Checked {$this->checked} document(s).");
        return self::SUCCESS;
    }

    /**
     * Due documents, never-checked first, excluding hosts that are paused.
     *
     * @return Collection<int, Document>
     */
    private function nextBatch(int $batchSize): Collection
    {
        $pausedHosts = array_keys($this->throttle->pausedHosts());
        $pausedOrgIds = array_keys(array_filter($this->orgHosts, fn($host) => in_array($host, $pausedHosts, true)));

        $query = Document::query()
            ->recheckable()
            ->join('document_remote_states as s', 's.document_id', '=', 'documents.id')
            ->where(function ($q) {
                $q->whereNull('s.next_check_at')->orWhere('s.next_check_at', '<=', now());
            })
            ->when($pausedOrgIds, fn($q) => $q->whereNotIn('documents.organisation_id', $pausedOrgIds))
            ->when($this->option('org'), fn($q, $org) => $q->where('documents.organisation_id', (int)$org))
            ->when($this->option('document'), fn($q, $ids) => $q->whereIn('documents.id', array_map('intval', $ids)))
            ->orderByRaw('s.next_check_at is not null, s.next_check_at, documents.id')
            ->limit($batchSize)
            ->select('documents.*')
            ->with('organisation');

        if ($this->option('dry-run') || !empty($this->option('document'))) {
            // A dry run writes no next_check_at, so a "due" filter would loop
            // forever; the same applies to explicit ids that are not yet due.
            $query = Document::query()
                ->recheckable()
                ->leftJoin('document_remote_states as s', 's.document_id', '=', 'documents.id')
                ->when($this->option('org'), fn($q, $org) => $q->where('documents.organisation_id', (int)$org))
                ->when($this->option('document'), fn($q, $ids) => $q->whereIn('documents.id', array_map('intval', $ids)))
                ->when($pausedOrgIds, fn($q) => $q->whereNotIn('documents.organisation_id', $pausedOrgIds))
                ->orderByRaw('s.next_check_at is not null, s.next_check_at, documents.id')
                ->limit($batchSize)
                ->select('documents.*')
                ->with('organisation');

            if ($this->checked > 0) {
                // Without a "due" filter the same rows would come back forever:
                // these modes run a single batch (use --limit/--batch-size).
                return collect();
            }
        }

        return $query->get();
    }

    /**
     * Round-robin across hosts so waiting on one host's spacing is spent on
     * another.
     *
     * @param Collection<int, Document> $batch
     */
    private function processBatch(Collection $batch, bool $dryRun, ?int $limit): void
    {
        $byHost = $batch->groupBy(fn(Document $d) => HostThrottle::host($d->url))->map->values()->all();

        while ($byHost && !$this->stopRequested) {
            foreach (array_keys($byHost) as $host) {
                if ($this->stopRequested || ($limit !== null && $this->checked >= $limit)) {
                    return;
                }

                if ($this->throttle->isPaused($host) || $byHost[$host]->isEmpty()) {
                    unset($byHost[$host]);
                    continue;
                }

                /** @var Document $document */
                $document = $byHost[$host]->shift();
                $this->throttle->waitFor($host);
                $this->checkOne($document, $host, $dryRun);
            }
        }
    }

    private function checkOne(Document $document, string $host, bool $dryRun): void
    {
        try {
            $check = $this->checker->check($document, $dryRun);
        } catch (\Throwable $e) {
            Log::error("recheck: unexpected failure for document {$document->id}: {$e->getMessage()}", ['exception' => $e]);
            $this->error("#{$document->id} exception: {$e->getMessage()}");
            $check = RemoteCheck::error(RemoteCheck::ERROR_CONNECTION, null, $e->getMessage());
            if (!$dryRun) {
                $this->checker->apply($document, $check);
            }
        }

        $this->checked++;
        $this->heartbeat->count('checks');

        if ($check->isError()) {
            $this->heartbeat->count('errors');
            $until = $this->throttle->recordError($host, $check);
            $this->line(sprintf('#%d %s error:%s%s', $document->id, $document->organisation->slug, $check->errorKind, $check->httpStatus ? " http {$check->httpStatus}" : ''));
            if ($until) {
                $this->warn("Paused $host until {$until->toDateTimeString()} ({$check->errorKind}).");
                Log::warning("recheck: paused $host until $until ({$check->errorKind})");
            }
            return;
        }

        $this->throttle->recordSuccess($host);

        $changed = !$dryRun && $this->lastChangeIsFresh($document);
        if ($changed) {
            $this->heartbeat->count('changes');
            if ($document->remoteState?->personal_data_restriction) {
                $this->heartbeat->count('personal_data');
            }
        }

        $this->line(sprintf(
            '#%d %s %s%s%s',
            $document->id,
            $document->organisation->slug,
            $check->outcome,
            $check->bases ? ' [' . $check->basisString() . ']' : '',
            $changed ? '  <- CHANGED' : ''
        ));
    }

    private function lastChangeIsFresh(Document $document): bool
    {
        $document->unsetRelation('remoteState');
        return DocumentStatusChange::query()
            ->where('document_id', $document->id)
            ->where('created_at', '>=', now()->subSeconds(5))
            ->exists();
    }

    private function loadOrgHosts(): void
    {
        $this->orgHosts = Organisation::query()->get()
            ->mapWithKeys(fn(Organisation $o) => [$o->id => HostThrottle::host($o->registry_base_uri)])
            ->all();
    }

    private function idle(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->stopRequested; $i++) {
            sleep(1);
            $this->dispatchSignals();
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $stop = function (int $signal) {
            $this->stopRequested = true;
            $this->warn("Received signal $signal, finishing current check…");
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Once a day, after the configured hour, mail a summary of new queue rows.
     */
    private function maybeSendDigest(): void
    {
        $today = now()->toDateString();
        if (now()->hour < (int)config('recheck.digest_hour', 8)) {
            return;
        }
        if (Cache::get(Heartbeat::KEY_DIGEST_DATE) === $today) {
            return;
        }

        $to = config('mail.admin.address');
        if (!$to) {
            Cache::forever(Heartbeat::KEY_DIGEST_DATE, $today);
            return;
        }

        $pending = DocumentStatusChange::query()->unacknowledged()->count();
        $since = now()->subDay();
        $new = DocumentStatusChange::query()->where('created_at', '>=', $since)->count();

        if ($new > 0) {
            Mail::to($to)->send(new RecheckDigestMail($since));
            $this->info("Digest sent: $new new, $pending pending.");
        }

        Cache::forever(Heartbeat::KEY_DIGEST_DATE, $today);
    }
}
