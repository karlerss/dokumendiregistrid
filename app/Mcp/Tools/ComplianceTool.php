<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use App\Support\ClientIp;
use App\Models\ReviewItem;
use Laravel\Mcp\Server\Tool;

abstract class ComplianceTool extends Tool
{
    /** Recorded on every decision and log row. */
    protected function actor(): string
    {
        return 'mcp:' . (request() ? ClientIp::of(request()) : 'local');
    }

    protected function documentSummary(?Document $document): ?array
    {
        if ($document === null) {
            return null;
        }

        return [
            'id' => $document->id,
            'title' => $document->title,
            'organisation' => $document->organisation?->name,
            'reference' => $document->reference,
            'registration_date' => $document->registration_date?->toDateString(),
            'page_url' => rtrim((string)config('app.url'), '/') . "/dokumendid/{$document->id}",
            'source_url' => $document->url,
            'visible' => (bool)$document->visible,
            'removed_at' => $document->removed_at?->toIso8601String(),
            'redacted_at' => $document->redacted_at?->toIso8601String(),
        ];
    }

    protected function itemSummary(ReviewItem $item): array
    {
        return [
            'item_id' => $item->id,
            'status' => $item->status,
            'priority' => $item->priority,
            'document_id' => $item->document_id,
            'file_key' => $item->file_key,
            'sources' => array_values(array_unique(array_column($item->flags ?? [], 'source'))),
            'references' => $item->references(),
            'personal_data' => $item->isPersonalData(),
            'requesters' => $item->requesters(),
            'first_flagged_at' => $item->first_flagged_at?->toIso8601String(),
            'decision' => $item->decision,
            'decision_reason' => $item->decision_reason,
            'decided_by' => $item->decided_by,
            'decided_at' => $item->decided_at?->toIso8601String(),
        ];
    }
}
