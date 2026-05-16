<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FillFileContentsColumn extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'document:fill-file-contents';

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
        // to the Document file_contents column, add all the file contents from the files table
        \Illuminate\Support\Facades\DB::unprepared(<<<SQLITE
        update documents
        set file_contents = (
            select group_concat(f.name || ' ' || f.contents, '  ') as file_contents
            from files f
            where f.document_id = documents.id
        ) ;
        SQLITE
        );

    }
}
