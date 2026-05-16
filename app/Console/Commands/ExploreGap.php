<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ExploreGap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:explore';

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
        $alphabet = range('a', 'z');

        $permutations = (new \drupol\phpermutations\Generators\Permutations($alphabet, 4))->toArray();
        $permutations = array_map(fn($a) => implode('', $a), $permutations);

        foreach ($permutations as $str) {
            try {
                $res = Http::head("https://adr.rik.ee/$str/");
                if ($res->successful()) {
                    echo $str . "\n";
                }
            } catch (\Exception $e) {
            }
        }

        dd('done');

        dd($permutations);


        dd();
        //https://adr.rik.ee/jm/dokument/15198429
        //https://adr.rik.ee/jm/dokument/15195034

        $start = 15195034;
        $end = 15198429;

        for ($i = $start; $i < $end; $i++) {
            $res = Http::head("https://adr.rik.ee/jm/dokument/$i");
            dd($res);
        }


    }
}
