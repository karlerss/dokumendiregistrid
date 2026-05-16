<?php

namespace Database\Seeders;

use App\Models\Organisation;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Symfony\Component\DomCrawler\Crawler;

class OrgSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // read registries.txt line by line and loop
        $file = fopen(base_path('resources/registries.txt'), 'r');
        while (!feof($file)) {
            $line = fgets($file);
            $line = trim($line);
            if ($line) {
                try {
                    $contents = file_get_contents($line);
                } catch (\Exception $e) {
                    continue;
                }
                $c = new Crawler($contents);
                $name = $c->filter('#footer > div > p > strong')->first()->text();
                $urlParts = explode('/', rtrim($line, '/'));

                Organisation::query()->updateOrCreate([
                    'registry_base_uri' => $line,
                ], [
                    'name' => $name,
                    'slug' => end($urlParts),
                ]);
            }
        }

        Organisation::query()->updateOrCreate([
            'registry_base_uri' => 'https://adr.rmk.ee',
        ], [
            'name' => 'Riigimetsa Majandamise Keskus',
            'slug' => 'rmk',
            'fetcher_type' => 'rmk',
        ]);

        Organisation::query()->updateOrCreate([
            'registry_base_uri' => 'https://dhs.riigikantselei.ee/avalikteave.nsf/',
        ], [
            'name' => 'Riigikantselei',
            'slug' => 'riigikantselei',
            'fetcher_type' => 'riigikantselei-dhs',
        ]);

        Organisation::query()->updateOrCreate([
            'registry_base_uri' => 'https://www.riigikogu.ee/tegevus/dokumendiregister/',
        ], [
            'name' => 'Riigikogu',
            'slug' => 'riigikogu',
            'fetcher_type' => 'riigikogu',
        ]);

        Organisation::query()->updateOrCreate([
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
        ], [
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'fetcher_type' => 'tallinn-atp',
        ]);
    }
}
