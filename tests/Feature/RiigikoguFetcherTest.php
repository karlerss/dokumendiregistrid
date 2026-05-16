<?php

namespace Tests\Feature;

use App\Lib\Fetcher\RiigikoguFetcher;
use App\Models\Document;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RiigikoguFetcherTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organisation
    {
        return Organisation::create([
            'name' => 'Riigikogu',
            'slug' => 'riigikogu',
            'registry_base_uri' => 'https://www.riigikogu.ee/tegevus/dokumendiregister/',
            'fetcher_type' => 'riigikogu',
        ]);
    }

    public function test_parse_list_page_filters_out_uksuse_istungi_paevakorrapunkt()
    {
        $html = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_list_2026-05-14_page1.html');

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        [$uuids, $hasNext] = $fetcher->parseListPage($html);

        // The fixture contains 25 rows: 18 of "Üksuse istungi päevakorrapunkt"
        // and 7 of other types (Käskkiri, Kiri, Protokoll).
        $this->assertCount(7, $uuids);
        $this->assertTrue($hasNext);

        $this->assertContains('5a0c8190-b1d1-49ba-bf96-bc6444eb5afa', $uuids);
        $this->assertContains('e8a72f4e-a8d9-4d8e-95dc-e755c619123f', $uuids);
    }

    public function test_parse_list_page_reports_no_next_when_pagination_lacks_next_link()
    {
        $html = '<html><body><table><tbody class="search-results-eelnoud"></tbody></table>'
            . '<div class="pagination"><a class="prev" href="?pg=1">Eelmine</a></div></body></html>';

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        [$uuids, $hasNext] = $fetcher->parseListPage($html);

        $this->assertSame([], $uuids);
        $this->assertFalse($hasNext);
    }

    public function test_list_paginates_until_no_next_link()
    {
        $page1 = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_list_2026-05-14_page1.html');
        // Strip the "next" link so pagination stops after page 2.
        $page2 = str_replace('class="next"', 'class="no-more"', $page1);

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';
        Http::fake([
            $base . '?pg=1*' => Http::response($page1, 200),
            $base . '?pg=2*' => Http::response($page2, 200),
        ]);

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        $uuids = $fetcher->list(Carbon::parse('2026-05-14'));

        // Each page yields 7 non-filtered UUIDs.
        $this->assertCount(14, $uuids);
    }

    public function test_store_maps_public_document_with_files()
    {
        $listHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_list_2026-05-14_page1.html');
        $docHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_5a0c8190.html');

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';
        $listHtmlNoNext = str_replace('class="next"', 'class="no-more"', $listHtml);

        Http::fake([
            $base . '?pg=1*' => Http::response($listHtmlNoNext, 200),
            $base . 'dokument/5a0c8190-b1d1-49ba-bf96-bc6444eb5afa/' => Http::response($docHtml, 200),
        ]);

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        $fetcher->setDownloadFiles(false);

        // list() populates the type cache used by store().
        $uuids = $fetcher->list(Carbon::parse('2026-05-14'));
        $this->assertContains('5a0c8190-b1d1-49ba-bf96-bc6444eb5afa', $uuids);

        $document = $fetcher->store('5a0c8190-b1d1-49ba-bf96-bc6444eb5afa');

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'original_id' => '5a0c8190-b1d1-49ba-bf96-bc6444eb5afa',
            'url' => $base . 'dokument/5a0c8190-b1d1-49ba-bf96-bc6444eb5afa/',
            'reference' => '4-11/26-12',
            'type' => 'Käskkiri',
            'title' => 'Riigikogu Kantselei 2026. aasta eelarve kinnitamine koos 2025. aastast ülekantud vahenditega',
            'dossier' => 'Käskkirjad 2026',
            'restriction' => 'Avalik',
        ]);

        $this->assertEquals('2026-05-14', $document->registration_date->format('Y-m-d'));
        $this->assertCount(0, $document->restrictions);
    }

    public function test_store_maps_restricted_document_with_basis()
    {
        $listHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_list_2026-05-14_page1.html');
        $docHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_e8a72f4e.html');

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';
        $listHtmlNoNext = str_replace('class="next"', 'class="no-more"', $listHtml);

        Http::fake([
            $base . '?pg=1*' => Http::response($listHtmlNoNext, 200),
            $base . 'dokument/e8a72f4e-a8d9-4d8e-95dc-e755c619123f/' => Http::response($docHtml, 200),
        ]);

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        $fetcher->setDownloadFiles(false);

        $fetcher->list(Carbon::parse('2026-05-14'));
        $document = $fetcher->store('e8a72f4e-a8d9-4d8e-95dc-e755c619123f');

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'original_id' => 'e8a72f4e-a8d9-4d8e-95dc-e755c619123f',
            'reference' => '1-6/26-93/1',
            'type' => 'Kiri',
            'title' => 'Kohtumenetluse praktikast',
            'dossier' => 'Kohtumenetluse praktikast',
            'restriction' => 'Asutusesiseseks kasutamiseks',
            'to' => 'M. K.',
            'method' => 'E-post',
        ]);

        $this->assertEquals('2026-05-14', $document->registration_date->format('Y-m-d'));

        $this->assertDatabaseHas('restriction_bases', [
            'document_id' => $document->id,
            'basis' => 'AvTS § 35 lg 1 p 12 teave, mis sisaldab isikuandmeid, kui sellisele teabele juurdepääsu võimaldamine kahjustaks oluliselt andmesubjekti eraelu puutumatust',
        ]);
        $this->assertCount(1, $document->restrictions);
    }

    public function test_store_downloads_files_using_content_disposition_filename()
    {
        // The link text on the doc page for dae70257 is just the label
        // "Sissetulev kiri" (no extension). The real filename with extension
        // is only available via the Content-Disposition response header.
        $listHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_list_2026-05-14_page1.html');
        $docHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_dae70257.html');

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';
        $listHtmlNoNext = str_replace('class="next"', 'class="no-more"', $listHtml);

        Storage::fake('r2');
        Http::fake([
            $base . '?pg=1*' => Http::response($listHtmlNoNext, 200),
            $base . 'dokument/dae70257-aeb9-431a-8fa9-0d77119189d0/' => Http::response($docHtml, 200),
            'https://www.riigikogu.ee/download/d1de0824-6ce1-4c51-af4a-2c11a7ce841a' => Http::response(
                'dummy body',
                200,
                [
                    'Content-Type' => 'text/html;charset=UTF-8',
                    'Content-Disposition' => "attachment; filename=\"=?UTF-8?Q?riigikogu.ee=5F2026.=5Faasta=5Flihtsustatud=5Fdigiligip=C3=A4=C3=A4setavuse=5Fseires.html?=\"; filename*=UTF-8''riigikogu.ee_2026._aasta_lihtsustatud_digiligip%C3%A4%C3%A4setavuse_seires.html",
                ]
            ),
        ]);

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        $fetcher->list(Carbon::parse('2026-05-14'));
        $document = $fetcher->store('dae70257-aeb9-431a-8fa9-0d77119189d0');

        $this->assertCount(1, $document->files);
        $this->assertSame(
            'riigikogu.ee_2026._aasta_lihtsustatud_digiligipääsetavuse_seires.html',
            $document->files->first()->name,
        );
    }

}
