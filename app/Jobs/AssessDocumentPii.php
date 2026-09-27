<?php

namespace App\Jobs;

use App\Lib\Pii\Assessor;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * (Re)computes the rules-based assessment for a document. Advisory only.
 */
class AssessDocumentPii implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $documentId)
    {
    }

    public function handle(Assessor $assessor): void
    {
        $document = Document::find($this->documentId);
        if ($document) {
            $assessor->assess($document);
        }
    }
}
