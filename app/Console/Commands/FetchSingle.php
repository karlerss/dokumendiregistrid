<?php

namespace App\Console\Commands;

use App\Lib\Fetcher\AdrFetcher;
use App\Models\Organisation;
use Illuminate\Console\Command;

class FetchSingle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-single {orgID} {docId}';

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
        $org = Organisation::findOrFail($this->argument('orgID'));
        $fetcher = new AdrFetcher($org);
        $fetcher->store($this->argument('docId'));
    }
}
