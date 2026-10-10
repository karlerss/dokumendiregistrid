<?php

namespace App\Lib\Compliance;

use App\Jobs\ExtractDocumentPii;
use App\Lib\Pii\Assessor;
use App\Lib\Pii\Extractor;
use App\Models\Document;
use App\Models\PiiExtraction;

/**
 * Queues a PII extraction for a document that got a takedown request, so
 * the assessment (and with it a redaction plan) is ready when the request
 * is reviewed. Runs on the queue; does nothing when the document already
 * has a usable extraction, one queued, or no text layer.
 */
class TakedownPiiCheck
{
    /** The queued extraction, or null when none was needed. */
    public function queue(Document $document): ?PiiExtraction
    {
        if ($document->isRemoved() || Assessor::latestUsableExtraction($document)) {
            return null;
        }
        $latest = PiiExtraction::query()
            ->where('document_id', $document->id)
            ->where('prompt_version', Extractor::PROMPT_VERSION)
            ->latest('id')
            ->first();
        if ($latest && in_array($latest->status, [PiiExtraction::STATUS_PENDING, PiiExtraction::STATUS_NEEDS_OCR], true)) {
            return null; // already queued, or nothing to read without OCR
        }

        $row = Extractor::request($document, PiiExtraction::REQUESTED_BY_TAKEDOWN);
        $row->forceFill(['queued_at' => now()])->save();
        ExtractDocumentPii::dispatch($row->id);
        return $row;
    }
}
