<?php

namespace Tests\Feature;

use App\Lib\Fetcher\RiigikantseleiFetcher;
use App\Models\Document;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RiigikantseleiFetcherTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organisation
    {
        return Organisation::create([
            'name' => 'Riigikantselei',
            'slug' => 'rkn',
            'registry_base_uri' => 'https://dhs.riigikantselei.ee/avalikteave.nsf/',
            'fetcher_type' => 'riigikantselei-dhs',
        ]);
    }

    public function test_it_maps_public_incoming_letter_fields_to_database()
    {
        $xml = file_get_contents(__DIR__ . '/../__fixtures/rk_document_NT00415122.xml');

        Http::fake([
            'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00415122?open' => Http::response($xml, 200),
        ]);

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(hexdec('00415122'));

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => (string) hexdec('00415122'),
            'url' => 'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00415122?open',
            'reference' => '26-00998-1',
            'type' => 'Kiri',
            'title' => 'Elanikkonnakaitse e-koolitus',
            'restriction' => 'Avalik',
            'to' => 'Päästeamet',
            'method' => 'E-post',
        ]);

        $this->assertEquals('2026-05-13', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_maps_restricted_document_and_stores_restriction_basis()
    {
        $xml = file_get_contents(__DIR__ . '/../__fixtures/rk_document_NT00415146.xml');

        Http::fake([
            'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00415146?open' => Http::response($xml, 200),
        ]);

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(hexdec('00415146'));

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => (string) hexdec('00415146'),
            'url' => 'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00415146?open',
            'reference' => '26-00992-1',
            'type' => 'Määruse eelnõu',
            'restriction' => 'Asutusesiseseks kasutamiseks',
            'to' => 'Rahandusministeerium',
        ]);

        $this->assertDatabaseHas('restriction_bases', [
            'document_id' => $document->id,
            'basis' => 'AvTS § 35 lg 2 p 1 - Õigusaktide eelnõud enne nende kooskõlastamiseks saatmist või vastuvõtmiseks esitamist',
        ]);

        $this->assertEquals('2026-05-13', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_falls_back_to_doctype_when_subject_is_missing()
    {
        $xml = file_get_contents(__DIR__ . '/../__fixtures/rk_document_NT0041518E.xml');

        Http::fake([
            'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT0041518E?open' => Http::response($xml, 200),
        ]);

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(hexdec('0041518E'));

        $this->assertInstanceOf(Document::class, $document);
        // The XML has no <field name="subject"> at the top level for this document.
        // Title should fall back to the doctype ("Dokumendi liik").
        $this->assertEquals('Kabinetinõupidamise materjalid', $document->title);
        $this->assertEquals('26-01009-1', $document->reference);
        $this->assertEquals('Kabinetinõupidamise materjalid', $document->type);
        $this->assertEquals('Asutusesiseseks kasutamiseks', $document->restriction);
    }

    public function test_parse_document_xml_extracts_file_links()
    {
        $xml = file_get_contents(__DIR__ . '/../__fixtures/rk_document_NT00415122.xml');

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $result = $fetcher->parseDocumentXml($xml);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('_file_urls', $result);
        $this->assertArrayHasKey('_file_names', $result);

        $this->assertCount(2, $result['_file_names']);
        $this->assertEquals('Elanikkonnakaitse e-koolitus.pdf', $result['_file_names'][0]);
        $this->assertEquals('Kutse tootajatele.pdf', $result['_file_names'][1]);

        $this->assertEquals(
            'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00415122/%24file/Elanikkonnakaitse%20e-koolitus.pdf',
            $result['_file_urls'][0]
        );
        $this->assertEquals(
            'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00415122/%24file/Kutse%20tootajatele.pdf',
            $result['_file_urls'][1]
        );
    }

    public function test_parse_document_xml_does_not_extract_files_for_restricted_document()
    {
        $xml = file_get_contents(__DIR__ . '/../__fixtures/rk_document_NT00415146.xml');

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $result = $fetcher->parseDocumentXml($xml);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('_file_urls', $result);
        $this->assertArrayNotHasKey('_file_names', $result);
    }

    public function test_list_returns_ids_from_search_xml()
    {
        $page1 = file_get_contents(__DIR__ . '/../__fixtures/rk_search_2026-05-13.xml');

        Http::fake(function ($request) use ($page1) {
            $url = $request->url();
            // Page returned 15 results which is < page size 20, so only one request expected.
            if (str_contains($url, 'Start=1')) {
                return Http::response($page1, 200);
            }
            return Http::response('', 404);
        });

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $ids = $fetcher->list(Carbon::parse('2026-05-13'));

        $this->assertCount(15, $ids);
        $this->assertContains(hexdec('00415122'), $ids);
        $this->assertContains(hexdec('00415146'), $ids);

        // Verify the search URL is built correctly with the date query.
        Http::assertSent(function ($request) {
            $url = $request->url();
            return str_contains($url, 'dhs.riigikantselei.ee/avalikteave.nsf/search')
                && str_contains($url, 'open')
                && str_contains($url, rawurlencode('[date]>=13.05.2026 AND [date]<=13.05.2026'))
                && str_contains($url, 'Start=1')
                && str_contains($url, 'Count=20');
        });
    }

    public function test_it_downloads_files_with_names_from_document_xml()
    {
        // Use synthetic XML with .txt extensions to avoid invoking real document parsers.
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<document form="InDoc" noteid="NT00500001">
<field name="docid">26-TEST-1</field>
<field name="date">13.05.2026</field>
<field name="doctype">Kiri</field>
<field name="subject">File download test</field>
<field name="companyname">Päästeamet</field>
<file size="10" href="/avalikteave.nsf/documents/NT00500001/\$file/file with space.txt">file with space.txt</file>
<file size="10" href="/avalikteave.nsf/documents/NT00500001/\$file/second.txt">second.txt</file>
</document>
XML;

        $docUrl = 'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00500001?open';
        $fileUrl1 = 'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00500001/%24file/file%20with%20space.txt';
        $fileUrl2 = 'https://dhs.riigikantselei.ee/avalikteave.nsf/documents/NT00500001/%24file/second.txt';

        Http::fake([
            $docUrl => Http::response($xml, 200),
            $fileUrl1 => Http::response('content 1', 200, ['Content-Type' => 'text/plain']),
            $fileUrl2 => Http::response('content 2', 200, ['Content-Type' => 'text/plain']),
        ]);

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $fetcher->setDownloadFiles(true);
        $document = $fetcher->store(hexdec('00500001'));

        $this->assertCount(2, $document->files);
        $names = $document->files->pluck('name')->toArray();
        $this->assertContains('file with space.txt', $names);
        $this->assertContains('second.txt', $names);
    }

    public function test_list_paginates_through_multiple_pages()
    {
        // Build a fake first page with exactly 20 documents to force pagination.
        $docs = '';
        for ($i = 0; $i < 20; $i++) {
            $noteId = 'NT' . sprintf('%08X', 0x00500000 + $i);
            $docs .= "<document noteid=\"$noteId\" href=\"/avalikteave.nsf/documents/$noteId?open\">"
                . '<field name="date">13.05.2026</field>'
                . '<field name="docid">26-X-' . $i . '</field>'
                . '</document>';
        }
        $fullPage = '<?xml version="1.0" encoding="UTF-8"?><entries>' . $docs . '</entries>';
        $emptyPage = file_get_contents(__DIR__ . '/../__fixtures/rk_search_empty.xml');

        Http::fake(function ($request) use ($fullPage, $emptyPage) {
            $url = $request->url();
            if (str_contains($url, 'Start=1')) {
                return Http::response($fullPage, 200);
            }
            if (str_contains($url, 'Start=21')) {
                return Http::response($emptyPage, 200);
            }
            return Http::response('', 404);
        });

        $org = $this->makeOrg();
        $fetcher = new RiigikantseleiFetcher($org);
        $ids = $fetcher->list(Carbon::parse('2026-05-13'));

        $this->assertCount(20, $ids);
        $this->assertContains(0x00500000, $ids);
        $this->assertContains(0x00500013, $ids);
    }
}
