<?php

namespace App\Lib\Recheck;

use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Runs one remote probe for a document and records the outcome. Never changes
 * anything a visitor can see: transitions only produce review-queue rows.
 */
class DocumentChecker
{
    public function __construct(private CheckPolicy $policy)
    {
    }

    public static function fromConfig(): self
    {
        return new self(CheckPolicy::fromConfig());
    }

    /**
     * Probe the registry and, unless $dryRun, persist the result.
     */
    public function check(Document $document, bool $dryRun = false): RemoteCheck
    {
        $check = $document->organisation->getFetcher()->checkRemote($document);

        if (!$dryRun) {
            $this->apply($document, $check);
        }

        return $check;
    }

    /**
     * Persist a probe result.
     *
     * @return DocumentStatusChange|null the review-queue row, if the probe found a change
     */
    public function apply(Document $document, RemoteCheck $check, ?CarbonInterface $now = null): ?DocumentStatusChange
    {
        $now = Carbon::instance($now ?? now());

        return DB::transaction(function () use ($document, $check, $now) {
            /** @var DocumentRemoteState $state */
            $state = DocumentRemoteState::query()->firstOrNew(['document_id' => $document->id]);

            if ($check->isError()) {
                $state->check_error_count = $state->check_error_count + 1;
                $state->last_http_status = $check->httpStatus;
                $state->next_check_at = $this->policy->nextCheckAfterError($state->check_error_count, $now);
                $state->save();
                return null;
            }

            $fromStatus = $state->remote_status;
            $fromRestriction = $state->remote_restriction;
            $fromBasis = $state->remote_restriction_basis;
            $fromPersonalData = (bool)$state->personal_data_restriction;

            $toStatus = $check->outcome;
            $toRestriction = $check->restriction;
            $toBasis = $check->basisString();
            $toPersonalData = $check->isPersonalData();

            $state->remote_status = $toStatus;
            $state->remote_restriction = $toRestriction;
            $state->remote_restriction_basis = $toBasis;
            $state->remote_restriction_change_basis = $check->changeBasis;
            $state->personal_data_restriction = $toPersonalData;
            $state->checked_at = $now;
            $state->check_error_count = 0;
            $state->last_http_status = $check->httpStatus;
            $state->next_check_at = $this->policy->nextCheckAfterSuccess($document, $check, $now);
            $state->save();

            if (!$this->isTransition($document, $fromStatus, $toStatus, $fromRestriction, $toRestriction, $fromBasis, $toBasis, $fromPersonalData, $toPersonalData)) {
                return null;
            }

            return DocumentStatusChange::query()->create([
                'document_id' => $document->id,
                'occurred_at' => $now,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'from_restriction' => $fromRestriction,
                'to_restriction' => $toRestriction,
                'basis' => $toBasis,
                'personal_data' => $toPersonalData,
                'http_status' => $check->httpStatus,
            ]);
        });
    }

    private function isTransition(
        Document $document,
        ?string $fromStatus,
        string $toStatus,
        ?string $fromRestriction,
        ?string $toRestriction,
        ?string $fromBasis,
        ?string $toBasis,
        bool $fromPersonalData,
        bool $toPersonalData,
    ): bool {
        // A never-checked document that was public at ingest counts as
        // previously public, so a first probe finding it restricted or gone is
        // a change worth reviewing.
        $effectiveFrom = $fromStatus ?? ($document->restriction === 'Avalik' ? RemoteCheck::PUBLIC : null);

        if ($effectiveFrom !== $toStatus) {
            return true;
        }

        // Still restricted, but on a different basis (or the personal-data
        // classification flipped): low priority, but recorded.
        if ($toStatus === RemoteCheck::RESTRICTED && $fromStatus !== null) {
            return $fromRestriction !== $toRestriction
                || $fromBasis !== $toBasis
                || $fromPersonalData !== $toPersonalData;
        }

        return false;
    }
}
