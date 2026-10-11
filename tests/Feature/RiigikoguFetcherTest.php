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

    public function test_parse_redesigned_document_page()
    {
        $html = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_redesign_c1ba0366.html');
        $data = (new RiigikoguFetcher($this->makeOrg()))->parseDocumentPage($html);

        $this->assertSame('MAJK protokoll nr 190 5.05.2026', $data['Pealkiri']);
        $this->assertSame('15.05.2026', $data['Dokumendi loomise kuupäev']);
        $this->assertSame('Avalik', $data['_restriction']);
        $this->assertCount(4, $data['_file_urls']);
        $this->assertContains('Keskkonnaamet.pdf', $data['_file_names']);
        $this->assertStringStartsWith('https://www.riigikogu.ee/wp-json/riigikogu/v1/files/', $data['_file_urls'][0]);
    }

    /** A documents-API page with the given rows: [uuid, documentType, reference]. */
    private function apiList(array $rows, int $number = 0, int $totalPages = 1): array
    {
        return [
            '_embedded' => ['content' => array_map(fn($r) => [
                'uuid' => $r[0], 'documentType' => $r[1], 'reference' => $r[2] ?? null,
                'title' => 'x', 'created' => '2026-05-14T10:00:00.000',
            ], $rows)],
            'page' => ['size' => 100, 'totalElements' => count($rows), 'totalPages' => $totalPages, 'number' => $number],
        ];
    }

    public function test_parse_list_response_skips_unit_agenda_items()
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../__fixtures/riigikogu_api_documents_2026-10-08.json'), true, flags: JSON_INVALID_UTF8_IGNORE);

        [$uuids, $hasNext] = (new RiigikoguFetcher($this->makeOrg()))->parseListResponse($json);

        // The recorded day has 69 rows: 51 unit agenda items and 18 documents.
        $this->assertCount(18, $uuids);
        $this->assertFalse($hasNext);
    }

    public function test_list_pages_through_the_api_until_the_last_page()
    {
        Http::fake([
            'www.riigikogu.ee/wp-json/riigikogu/v1/ems/documents?page=0*' => Http::response($this->apiList([
                ['aaaaaaaa-0000-0000-0000-000000000001', 'letterDocument'],
                ['aaaaaaaa-0000-0000-0000-000000000002', 'unitAgendaItemDocument'],
            ], 0, 2), 200),
            'www.riigikogu.ee/wp-json/riigikogu/v1/ems/documents?page=1*' => Http::response($this->apiList([
                ['aaaaaaaa-0000-0000-0000-000000000003', 'protokoll'],
            ], 1, 2), 200),
        ]);

        $uuids = (new RiigikoguFetcher($this->makeOrg()))->list(Carbon::parse('2026-05-14'));

        $this->assertSame(['aaaaaaaa-0000-0000-0000-000000000001', 'aaaaaaaa-0000-0000-0000-000000000003'], $uuids);
        Http::assertSent(fn($request) => str_contains($request->url(), 'createdStart=2026-05-14') && str_contains($request->url(), 'createdEnd=2026-05-14'));
    }

    public function test_store_maps_public_document_with_files()
    {
        $docHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_5a0c8190.html');

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';

        Http::fake([
            'www.riigikogu.ee/wp-json/riigikogu/v1/ems/documents*' => Http::response($this->apiList([['5a0c8190-b1d1-49ba-bf96-bc6444eb5afa', 'directiveDocument', '4-11/26-12']]), 200),
            $base . 'dokument/5a0c8190-b1d1-49ba-bf96-bc6444eb5afa/' => Http::response($docHtml, 200),
        ]);

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        $fetcher->setDownloadFiles(false);

        // list() carries the type label used by store().
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
        $docHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_e8a72f4e.html');

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';

        Http::fake([
            'www.riigikogu.ee/wp-json/riigikogu/v1/ems/documents*' => Http::response($this->apiList([['e8a72f4e-a8d9-4d8e-95dc-e755c619123f', 'letterDocument', '1-6/26-93/1']]), 200),
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
        $docHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_dae70257.html');

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';

        Storage::fake('r2');
        Http::fake([
            'www.riigikogu.ee/wp-json/riigikogu/v1/ems/documents*' => Http::response($this->apiList([['dae70257-aeb9-431a-8fa9-0d77119189d0', 'letterDocument']]), 200),
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

    public function test_store_downloads_single_public_pdf_with_extension_from_header()
    {
        // Doc f0d829e2 has one public file whose link text is the label "Arupärimine"
        // (no extension). The real filename "AP_1060.pdf" comes from Content-Disposition.
        $docHtml = file_get_contents(__DIR__ . '/../__fixtures/riigikogu_document_f0d829e2.html');
        $pdfBody = file_get_contents(__DIR__ . '/../__fixtures/pii_ex/julg.pdf');

        $base = 'https://www.riigikogu.ee/tegevus/dokumendiregister/';

        Storage::fake('r2');
        Http::fake([
            $base . 'dokument/f0d829e2-03b2-4f47-b215-49544e895084/' => Http::response($docHtml, 200),
            'https://www.riigikogu.ee/download/73629e02-e261-407b-9b02-f16e656968b3' => Http::response(
                $pdfBody,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => "attachment; filename=\"=?UTF-8?Q?AP=5F1060.pdf?=\"; filename*=UTF-8''AP_1060.pdf",
                ]
            ),
        ]);

        $fetcher = new RiigikoguFetcher($this->makeOrg());
        $document = $fetcher->store('f0d829e2-03b2-4f47-b215-49544e895084');

        $this->assertCount(1, $document->files);
        $this->assertSame('AP_1060.pdf', $document->files->first()->name);
    }
}
