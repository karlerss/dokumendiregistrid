<?php

namespace App\Console\Commands;

use App\Lib\Fetcher\AdrFetcher;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Audit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'docs:audit';

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
        $string = '"Alus: AvTS § 35 lg 1 p %d" NOT "Struktuurüksus on tunnistatud"';

        echo "Remote visibility\tOrganisation\tDocument ID\tRemote url\tUrl\tSearch string\n";

        for ($i = 1; $i < 20; $i++) {
            $search = sprintf($string, $i);
            $ids = DB::table('fts_documents')
                ->join('documents', 'fts_documents.rowid', '=', 'documents.id')
                ->whereFullText('fts_documents', $search)->pluck('id');

            $ids->each(function ($id) use ($search) {
                $doc = Document::query()->findOrFail($id);
                $fetcher = new AdrFetcher($doc->organisation);
                $data = $fetcher->getPageData($doc->url);
                $current = data_get($data, '0.Juurdepääsupiirang');
                echo $current . "\t" . $doc->organisation->slug . "\t" . $doc->id . "\t" . $doc->url . "\t" . route('document', ['document' => $doc->id, 'slug' => \Illuminate\Support\Str::slug($doc->ai_title ?? $doc->title)]) . "\t" . $search . PHP_EOL;
            });
        }
    }
}
