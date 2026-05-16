<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

class ReindexDocumentsFts extends Command
{
    protected $signature = 'fts:reindex-documents {--chunk=1000}';
    protected $description = 'Reindex FTS5 for all documents in chunks using insert/delete';

    public function handle()
    {
        $chunkSize = (int)$this->option('chunk');

        $this->info("Reindexing documents FTS5 in chunks of {$chunkSize}...");

        $total = Document::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Document::select('id')->inRandomOrder()->orderBy('id')->chunk($chunkSize, function ($documents) use ($bar) {
            foreach ($documents as $doc) {
                $id = $doc->id;
                DB::statement("INSERT INTO fts_documents(fts_documents, rowid) VALUES('delete', ?)", [$id]);
                DB::statement("INSERT INTO fts_documents(fts_documents, rowid) VALUES('insert', ?)", [$id]);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\nReindexing complete.");
    }
}
