<?php

namespace App\Console\Commands;

use App\Lib\Fetcher\AdrFetcher;
use App\Models\Document;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FullAudit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:full {--recheck} {--after_timestamp=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $afterTimestamp = $this->option('after_timestamp');
        if ($afterTimestamp) {
            $afterTimestamp = Carbon::parse($afterTimestamp);
        }

        $query = Document::query()
            ->where('restriction', 'Avalik');

        if ($this->option('recheck')) {
            $query->whereNotNull('last_audit_check_at')
                ->whereIn('last_visibility', ['AK']);
        } else {
            $query
                ->when($afterTimestamp, function ($query, $afterTimestamp) {
                    return $query->where('last_audit_check_at', '<', $afterTimestamp);
                }, function ($q) {
                    return $q->whereNull('last_audit_check_at');
                });
        }

        $query->orderBy('id')
            ->chunkById(200, function ($documents) {
                $documents->each(function (Document $document) {
                    $this->auditDocument($document);
                });
            });
    }

    /**
     * @param Document $document
     * @return void
     */
    function auditDocument(Document $document): void
    {
        $this->info("Processing document: $document->id");
        $fetcher = new AdrFetcher($document->organisation);
        try {
            $data = retry(3, function () use ($fetcher, $document) {
                return $fetcher->getPageData($document->url);
            }, 5000);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            $document->last_audit_check_at = now();
            $document->last_visibility = 'Unknown';
            $document->save();
            return;
        }
        $current = data_get($data, '0.Juurdepääsupiirang');

        $document->last_audit_check_at = now();
        $document->last_visibility = $current;
        $document->last_reason = data_get($data, '0.Juurdepääsupiirangu alus');
        $document->last_reason_change = data_get($data, '0.Juurdepääsupiirangu muutmise alus');
        $document->save();
        $this->info(implode(' | ', [
            $document->id,
            $document->last_visibility,
            $document->last_reason,
            $document->last_reason_change,
        ]));
    }
}
