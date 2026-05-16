<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SitemapsController extends Controller
{
    private string $baseUrl;

    public function __construct()
    {
//        parent::__construct();
        $this->baseUrl = config('app.url');
    }

    public function index()
    {
        $xml = new \SimpleXMLElement("<?xml version='1.0' encoding='UTF-8' ?>\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" />');

        $sitemap = $xml->addChild('sitemap');
        $sitemap->addChild('loc', $this->baseUrl . '/sitemap-pages.xml');
        $sitemap->addChild('lastmod', Carbon::parse(Document::query()->max('updated_at'))->toIso8601String());

        Organisation::query()->toBase()
            ->join('documents', 'organisations.id', '=', 'documents.organisation_id')
            ->groupByRaw("strftime('%Y', registration_date), organisations.slug")
            ->selectRaw("organisations.slug, strftime('%Y', registration_date) as year, max(documents.updated_at) as lastmod")
            ->get()->map(function ($item) use (&$xml) {
                $sitemap = $xml->addChild('sitemap');
                $sitemap->addChild('loc', $this->baseUrl . "/sitemaps/$item->slug/$item->year.xml");
                $sitemap->addChild('lastmod', Carbon::parse($item->lastmod)->toIso8601String());
            });

        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml',
        ]);
    }

    public function pages()
    {
        $xml = new \SimpleXMLElement("<?xml version='1.0' encoding='UTF-8' ?>\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" />');

        $home = $xml->addChild('url');
        $home->addChild('loc', $this->baseUrl);
        $home->addChild('priority', '1.0');
        $home->addChild('changefreq', 'hourly');
        $home->addChild('lastmod', Carbon::parse(Document::query()->max('updated_at'))->toIso8601String());

        $archive = $xml->addChild('url');
        $archive->addChild('loc', $this->baseUrl . '/arhiiv');
        $archive->addChild('priority', '1.0');
        $archive->addChild('lastmod', Carbon::parse(Document::query()->max('updated_at'))->toIso8601String());

        $about = $xml->addChild('url');
        $about->addChild('loc', $this->baseUrl . '/projektist');
        $about->addChild('priority', '1.0');
        $about->addChild('lastmod', Carbon::parse('2024-03-27')->toIso8601String());


        foreach (Organisation::query()->get() as $org) {
            $url = $xml->addChild('url');
            $url->addChild('loc', $this->baseUrl . "/arhiiv/$org->slug");
            $url->addChild('priority', '0.8');
            $url->addChild('lastmod', Carbon::parse(Document::query()->where('organisation_id', $org->id)->max('updated_at'))->toIso8601String());
        }

        Organisation::query()->toBase()
            ->join('documents', 'organisations.id', '=', 'documents.organisation_id')
            ->groupByRaw("strftime('%Y', registration_date), organisations.slug")
            ->selectRaw("organisations.slug, strftime('%Y', registration_date) as year, max(documents.updated_at) as lastmod")
            ->get()->map(function ($item) use (&$xml) {
                $sitemap = $xml->addChild('url');
                $sitemap->addChild('loc', $this->baseUrl . "/arhiiv/$item->slug/$item->year");
                $sitemap->addChild('lastmod', Carbon::parse($item->lastmod)->toIso8601String());
            });

        Organisation::query()->toBase()
            ->join('documents', 'organisations.id', '=', 'documents.organisation_id')
            ->groupByRaw("organisations.slug, strftime('%Y', registration_date), strftime('%m', registration_date)")
            ->selectRaw("organisations.slug, strftime('%Y', registration_date) as year,strftime('%m', registration_date) as month, max(documents.updated_at) as lastmod")
            ->get()->map(function ($item) use (&$xml) {
                $sitemap = $xml->addChild('url');
                $sitemap->addChild('loc', $this->baseUrl . "/arhiiv/$item->slug/$item->year/$item->month");
                $sitemap->addChild('lastmod', Carbon::parse($item->lastmod)->toIso8601String());
            });

        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml',
        ]);
    }

    public function orgSitemap($orgSlug, $year)
    {
        $xml = new \SimpleXMLElement("<?xml version='1.0' encoding='UTF-8' ?>\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" />');

        $org = Organisation::query()->where('slug', $orgSlug)->firstOrFail();

        Document::query()
            ->where('organisation_id', $org->id)
            ->whereRaw("strftime('%Y', registration_date) = ?", [$year])
            ->get()->map(function ($doc) use (&$xml) {
                $url = $xml->addChild('url');
                $url->addChild('loc', $this->baseUrl . "/dokumendid/$doc->id/" . Str::slug($doc->title, '-'));
                $url->addChild('priority', '0.6');
                $url->addChild('lastmod', $doc->updated_at->toIso8601String());
            });

        return response($xml->asXML(), 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
