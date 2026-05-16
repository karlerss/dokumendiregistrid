<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunFastRebuild extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'document:fast-rebuild';

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
        DB::unprepared(<<<SQLITE
        PRAGMA journal_mode = OFF;
        PRAGMA synchronous = OFF;
        PRAGMA temp_store = MEMORY;
        PRAGMA locking_mode = EXCLUSIVE;
        PRAGMA cache_size = -80000; -- ~80MB cache
        INSERT INTO fts_documents(fts_documents) VALUES('rebuild');
        SQLITE
        );
    }
}
