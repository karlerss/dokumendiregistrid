<?php

namespace Tests\Feature;

use App\Lib\Fetcher\TallinnFetcher;
use App\Models\Document;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TallinnFetcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_contract_doc_fields_to_database()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5709346.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5709346' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5709346);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5709346',
            'url' => 'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5709346',
            'reference' => '3.4-4/417-3',
            'type' => 'Leping',
            'title' => 'LEPINGU NR 3.4-4/417 MUUTMISE KOKKULEPE NR 2',
            'restriction' => 'Avalik',
            'to' => 'OÜ Astlanda Ehitus',
        ]);

        $this->assertEquals('2025-12-24', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_maps_incoming_letter_fields_to_database()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5618037.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5618037' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5618037);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5618037',
            'url' => 'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5618037',
            'reference' => 'F3-2/216-1',
            'type' => 'Saabunud kiri',
            'title' => 'Käskkiri',
            'restriction' => 'Avalik',
            'to' => 'Tallinna Linnavalitsus',
            'method' => 'e-post',
            'responsible' => 'Keiu Friedenthal (kantselei juhataja)',
        ]);

        $this->assertEquals('2025-06-30', $document->registration_date->format('Y-m-d'));
    }

    public function test_parse_html_returns_null_for_nonexistent_document()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_notfound.html');

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $result = $fetcher->parseHtml($htmlResponse);

        $this->assertNull($result);
    }

    public function test_parse_html_extracts_single_file_from_contract()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5709346.html');

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $result = $fetcher->parseHtml($htmlResponse);

        $this->assertArrayHasKey('Failid', $result);
        $this->assertArrayHasKey('_file_urls', $result);

        $this->assertCount(1, $result['Failid']);
        $this->assertEquals('3.4-4_417-3_24122025_757536.asice', $result['Failid'][0]);

        $this->assertCount(1, $result['_file_urls']);
        $this->assertEquals(
            'https://dhs.tallinn.ee/atp/failid/adr2/02021119/public/3.4_4_417_3_24122025_757536.377923.asice',
            $result['_file_urls'][0]
        );
    }

    public function test_parse_html_extracts_single_file_from_incoming_letter()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5618037.html');

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $result = $fetcher->parseHtml($htmlResponse);

        $this->assertArrayHasKey('Failid', $result);
        $this->assertCount(1, $result['Failid']);
        $this->assertEquals('T-4-1_25_6.asice', $result['Failid'][0]);

        $this->assertCount(1, $result['_file_urls']);
        $this->assertEquals(
            'https://dhs.tallinn.ee/atp/failid/adr2/02021114/public/T_4_1_25_6.63591.asice',
            $result['_file_urls'][0]
        );
    }

    public function test_parse_html_extracts_multiple_files()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5618203.html');

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $result = $fetcher->parseHtml($htmlResponse);

        $this->assertArrayHasKey('Failid', $result);
        $this->assertCount(3, $result['Failid']);
        $this->assertEquals('Register_24995501.pdf', $result['Failid'][0]);
        $this->assertEquals('maatüki_väljavõte_78404_408_0094.pdf', $result['Failid'][1]);
        $this->assertEquals('kinnisasjale juurdelõike tegemine _kadaka puiestee t2_ tallinn_.asice', $result['Failid'][2]);

        $this->assertCount(3, $result['_file_urls']);
        $this->assertEquals(
            'https://dhs.tallinn.ee/atp/failid/adr2/75014913/public/Register_24995501.137013.pdf',
            $result['_file_urls'][0]
        );
        $this->assertEquals(
            'https://dhs.tallinn.ee/atp/failid/adr2/75014913/public/maat_ki_v_ljav_te_78404_408_0094.137013.pdf',
            $result['_file_urls'][1]
        );
        $this->assertEquals(
            'https://dhs.tallinn.ee/atp/failid/adr2/75014913/public/kinnisasjale_juurdel_ike_tegemine__kadaka_puiestee_t2__tallinn_.137013.asice',
            $result['_file_urls'][2]
        );
    }

    public function test_parse_html_extracts_file_from_outgoing_letter()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5601063.html');

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $result = $fetcher->parseHtml($htmlResponse);

        $this->assertArrayHasKey('Failid', $result);
        $this->assertCount(1, $result['Failid']);
        $this->assertEquals('6-6_595-2_30052025_146890.asice', $result['Failid'][0]);

        $this->assertCount(1, $result['_file_urls']);
        $this->assertEquals(
            'https://dhs.tallinn.ee/atp/failid/adr2/2275431/public/6_6_595_2_30052025_146890.261524.asice',
            $result['_file_urls'][0]
        );
    }

    public function test_parse_html_handles_restricted_document_without_files()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5618411.html');

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $result = $fetcher->parseHtml($htmlResponse);

        // Restricted documents don't have Failid field
        $this->assertArrayNotHasKey('Failid', $result);
        $this->assertArrayNotHasKey('_file_urls', $result);
    }

    public function test_download_files_uses_parsed_filenames_not_url_basename()
    {
        // Create a modified HTML fixture that uses .txt extension (which doesn't require special parsing)
        $htmlResponse = <<<HTML
<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body>
<div id="document_container">
<div class="row">
<div class="detail_label">ID</div>
<div class="detail_desc">12345</div>
</div>
<div class="row">
<div class="detail_label">Liik</div>
<div class="detail_desc">Test document</div>
</div>
<div class="row">
<div class="detail_label">Reg nr</div>
<div class="detail_desc">TEST-123</div>
</div>
<div class="row">
<div class="detail_label">Reg kpv</div>
<div class="detail_desc">01.01.2025</div>
</div>
<div class="row">
<div class="detail_label">Pealkiri</div>
<div class="detail_desc">Test title</div>
</div>
<div class="row">
<div class="detail_label">Failid</div>
<div class="detail_desc"><a href="/atp/failid/test/proper-filename.txt">proper-filename.txt</a><br></div>
</div>
</div>
</body>
</html>
HTML;

        // Simulate Tallinn's server response - no Content-Disposition header
        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=9999999' => Http::response($htmlResponse, 200),
            // The file URL has a different basename than the actual filename
            // URL basename would be: url_mangled_name.12345.txt
            // Actual filename from HTML: proper-filename.txt
            'https://dhs.tallinn.ee/atp/failid/test/proper-filename.txt' => Http::response('fake file content', 200, [
                'Content-Type' => 'text/plain',
                // No Content-Disposition header - this is what Tallinn's server does
            ]),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        // Enable file downloads
        $fetcher->setDownloadFiles(true);
        $document = $fetcher->store(9999999);

        $this->assertInstanceOf(Document::class, $document);

        // Verify the file was saved with the correct filename from HTML
        $this->assertCount(1, $document->files);
        $file = $document->files->first();

        // The filename should be from the parsed HTML
        $this->assertEquals('proper-filename.txt', $file->name);
    }

    public function test_download_multiple_files_uses_parsed_filenames()
    {
        // Create a modified HTML fixture that uses .txt extension
        $htmlResponse = <<<HTML
<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body>
<div id="document_container">
<div class="row">
<div class="detail_label">ID</div>
<div class="detail_desc">12346</div>
</div>
<div class="row">
<div class="detail_label">Liik</div>
<div class="detail_desc">Test document</div>
</div>
<div class="row">
<div class="detail_label">Reg nr</div>
<div class="detail_desc">TEST-456</div>
</div>
<div class="row">
<div class="detail_label">Reg kpv</div>
<div class="detail_desc">01.01.2025</div>
</div>
<div class="row">
<div class="detail_label">Pealkiri</div>
<div class="detail_desc">Test with multiple files</div>
</div>
<div class="row">
<div class="detail_label">Failid</div>
<div class="detail_desc"><a href="/atp/failid/test/mangled_name_1.12346.txt">proper-name-1.txt</a><br>
<a href="/atp/failid/test/mangled_name_2.12346.txt">proper-name-2.txt</a><br>
<a href="/atp/failid/test/special_chars.12346.txt">file with spaces.txt</a><br>
</div>
</div>
</div>
</body>
</html>
HTML;

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=9999998' => Http::response($htmlResponse, 200),
            'https://dhs.tallinn.ee/atp/failid/test/mangled_name_1.12346.txt' => Http::response('content 1', 200),
            'https://dhs.tallinn.ee/atp/failid/test/mangled_name_2.12346.txt' => Http::response('content 2', 200),
            'https://dhs.tallinn.ee/atp/failid/test/special_chars.12346.txt' => Http::response('content 3', 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(true);
        $document = $fetcher->store(9999998);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertCount(3, $document->files);

        $fileNames = $document->files->pluck('name')->toArray();

        // Verify filenames are from parsed HTML, not URL basenames
        $this->assertContains('proper-name-1.txt', $fileNames);
        $this->assertContains('proper-name-2.txt', $fileNames);
        $this->assertContains('file with spaces.txt', $fileNames);

        // These would be the wrong filenames if we used URL basenames
        $this->assertNotContains('mangled_name_1.12346.txt', $fileNames);
        $this->assertNotContains('mangled_name_2.12346.txt', $fileNames);
    }

    public function test_real_tallinn_file_url_would_use_wrong_basename_without_fix()
    {
        // This test demonstrates the actual problem with Tallinn URLs
        // The URL basename contains an ID suffix that shouldn't be in the filename

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);

        // Parse the real fixture to see the difference between URL and filename
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5709346.html');
        $result = $fetcher->parseHtml($htmlResponse);

        // The URL basename would be: 3.4_4_417_3_24122025_757536.377923.asice
        $urlBasename = basename($result['_file_urls'][0]);
        $this->assertEquals('3.4_4_417_3_24122025_757536.377923.asice', $urlBasename);

        // But the actual filename from HTML is: 3.4-4_417-3_24122025_757536.asice
        $actualFilename = $result['Failid'][0];
        $this->assertEquals('3.4-4_417-3_24122025_757536.asice', $actualFilename);

        // They are different!
        $this->assertNotEquals($urlBasename, $actualFilename);
    }

    public function test_enumerate_backwards_calls_callback_for_existing_documents()
    {
        $existingResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5709346.html');
        $notFoundResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_notfound.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5709347' => Http::response($notFoundResponse, 200),
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5709346' => Http::response($existingResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $callbackData = [];

        $fetcher->enumerateBackwards(5709347, 5709346, function ($data) use (&$callbackData) {
            $callbackData[] = $data;
        });

        // Should only have one callback call (for 5709346, since 5709347 doesn't exist)
        $this->assertCount(1, $callbackData);
        $this->assertEquals(5709346, $callbackData[0]['_id']); // ID from HTML content
    }

    public function test_enumerate_forwards_calls_callback_for_existing_documents()
    {
        $existingResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5709346.html');
        $notFoundResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_notfound.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5709346' => Http::response($existingResponse, 200),
            'https://dhs.tallinn.ee/atp/*' => Http::response($notFoundResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $callbackData = [];

        // Start from 5709346, with maxFailures=2 so it stops after 2 consecutive failures
        $fetcher->enumerateForwards(5709346, 2, function ($data) use (&$callbackData) {
            $callbackData[] = $data;
        });

        // Should have one callback call (for 5709346)
        $this->assertCount(1, $callbackData);
        $this->assertEquals(5709346, $callbackData[0]['_id']);
    }

    public function test_it_maps_incoming_letter_with_files_5618203()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5618203.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5618203' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5618203);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5618203',
            'reference' => '7-3/1726-1',
            'type' => 'Saabunud kiri',
            'title' => 'dokumentide edastamine, Kadaka puiestee T2',
            'restriction' => 'Avalik',
            'to' => 'Tallinna Linnavaraamet',
            'method' => 'e-post',
            'responsible' => 'Ülle Schönberg (andmehaldur)',
        ]);

        $this->assertEquals('2025-06-30', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_maps_restricted_incoming_letter_5618411()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5618411.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5618411' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5618411);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5618411',
            'reference' => '7-3/1733-1',
            'type' => 'Saabunud kiri',
            'title' => 'Lepingu edastamine - isikliku kasutusõiguse seadmin',
            'restriction' => 'Asutusesiseseks kasutamiseks',
            'to' => 'Tallinna Linnavaraamet',
            'method' => 'elektroonselt (läbi Postipoisi)',
            'responsible' => 'Ülle Schönberg (andmehaldur)',
        ]);

        // Check restriction basis was saved
        $this->assertDatabaseHas('restriction_bases', [
            'document_id' => $document->id,
            'basis' => 'NotS § 3 lg 5',
        ]);

        $this->assertEquals('2025-06-30', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_maps_outgoing_letter_with_kellele_5601043()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5601043.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5601043' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5601043);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5601043',
            'reference' => 'S-4-3/443-2',
            'type' => 'Väljasaadetud kiri',
            'title' => 'Kohaliku omavalitsuse seisukoht kohtuasjas nr 2-25-6530',
            'restriction' => 'Asutusesiseseks kasutamiseks',
            'to' => 'Harju Maakohus',
            'method' => 'elektroonselt (läbi Postipoisi)',
            'responsible' => 'Mustamäe Linnaosa Valitsus',
        ]);

        // Check restriction basis was saved
        $this->assertDatabaseHas('restriction_bases', [
            'document_id' => $document->id,
            'basis' => 'AvTS § 35 lg 1 p 11, AvTS § 35 lg 1 p 12',
        ]);

        $this->assertEquals('2025-05-30', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_maps_outgoing_letter_without_kellele_5601037()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5601037.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5601037' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5601037);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5601037',
            'reference' => '7-5/1401-2',
            'type' => 'Väljasaadetud kiri',
            'title' => 'Sotsiaalhoolekandelise abi otsus',
            'restriction' => 'Asutusesiseseks kasutamiseks',
            'to' => 'Regina Truman (osakonna juhataja)',
            'method' => 'elektroonselt (läbi Postipoisi)',
            'responsible' => 'Lasnamäe Linnaosa Valitsus',
        ]);

        // Check restriction basis was saved
        $this->assertDatabaseHas('restriction_bases', [
            'document_id' => $document->id,
            'basis' => 'AvTS § 35 lg 1 p 12',
        ]);

        $this->assertEquals('2025-05-30', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_maps_contract_with_objekt_as_title_5711044()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5711044.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5711044' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5711044);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5711044',
            'url' => 'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5711044',
            'reference' => '3-25-13815/8',
            'type' => 'lepingud',
            'title' => 'Üürileping (otsustuskorras)',
            'restriction' => 'Avalik',
            'to' => 'Media Station OÜ',
        ]);

        $this->assertEquals('2025-12-30', $document->registration_date->format('Y-m-d'));
    }

    public function test_it_maps_public_outgoing_letter_with_kellele_5601063()
    {
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/tallinn_document_5601063.html');

        Http::fake([
            'https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=5601063' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Tallinna linn',
            'slug' => 'tallinn',
            'registry_base_uri' => 'https://dhs.tallinn.ee/atp/',
            'fetcher_type' => 'tallinn-atp',
        ]);

        $fetcher = new TallinnFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(5601063);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '5601063',
            'reference' => '6-6/595-2',
            'type' => 'Väljasaadetud kiri',
            'restriction' => 'Avalik',
            'to' => 'Eesti Linnade ja Valdade Liit',
            'method' => 'elektroonselt (läbi Postipoisi)',
            'responsible' => 'Tallinna Linnakantselei',
        ]);

        $this->assertEquals('2025-05-30', $document->registration_date->format('Y-m-d'));
    }
}

