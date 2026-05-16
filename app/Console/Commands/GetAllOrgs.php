<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use Illuminate\Console\Command;

class GetAllOrgs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:orgs {--with-names}';

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
        Organisation::query()->get()->each(function (Organisation $org) {
            if ($this->option('with-names')) {
                echo $org->name . "\t";
            }
            echo $org->id . "\n";
        });
    }
}
