<?php

namespace Tests\Feature;

use App\Lib\Fetcher\RMKFetcher;
use App\Models\Document;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RmkFetcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_doc_fields_to_database()
    {
        $jsonResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_411048.json');

        Http::fake([
            'https://adr.rmk.ee/api/dokument/411048' => Http::response($jsonResponse, 200, ['Content-Type' => 'application/json']),
        ]);

        $org = Organisation::create([
            'name' => 'Riigimetsa Majandamise Keskus',
            'slug' => 'rmk',
            'registry_base_uri' => 'https://adr.rmk.ee/',
            'fetcher_type' => 'rmk',
        ]);

        $fetcher = new RMKFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(411048);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '411048',
            'url' => 'https://adr.rmk.ee/dokument/411048',
            'reference' => '3-6.11/130',
            'type' => 'Leping',
            'title' => 'Leping_Jaagarahu laoplats_Sikassaare Vanametall',
            'function' => '3-6.11',
            'series' => 'Looduskaitseliste tööde ja teenuste lepingud',
            'restriction' => 'Avalik',
            'to' => 'Sikassaare Vanametall OÜ',
            'responsible' => 'Looduskaitseosakond',
        ]);

        $this->assertEquals('2025-12-23', $document->registration_date->format('Y-m-d'));
    }

    public function test_enumerate_backwards_calls_callback_for_existing_documents()
    {
        $existingResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_411048.json');
        $notFoundResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_411049.json');

        Http::fake([
            'https://adr.rmk.ee/api/dokument/411049' => Http::response($notFoundResponse, 200, ['Content-Type' => 'application/json']),
            'https://adr.rmk.ee/api/dokument/411048' => Http::response($existingResponse, 200, ['Content-Type' => 'application/json']),
        ]);

        $org = Organisation::create([
            'name' => 'Riigimetsa Majandamise Keskus',
            'slug' => 'rmk',
            'registry_base_uri' => 'https://adr.rmk.ee/',
            'fetcher_type' => 'rmk',
        ]);

        $fetcher = new RMKFetcher($org);
        $callbackData = [];

        $fetcher->enumerateBackwards(411049, 411048, function ($data) use (&$callbackData) {
            $callbackData[] = $data;
        });

        // Should only have one callback call (for 411048, since 411049 has data=false)
        $this->assertCount(1, $callbackData);
        $this->assertEquals(411048, $callbackData[0]['data']['id']);
    }

    public function test_enumerate_forwards_calls_callback_for_existing_documents()
    {
        $existingResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_411048.json');
        $notFoundResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_411049.json');

        Http::fake([
            'https://adr.rmk.ee/api/dokument/411048' => Http::response($existingResponse, 200, ['Content-Type' => 'application/json']),
            'https://adr.rmk.ee/api/dokument/411049' => Http::response($notFoundResponse, 200, ['Content-Type' => 'application/json']),
            'https://adr.rmk.ee/api/dokument/*' => Http::response($notFoundResponse, 200, ['Content-Type' => 'application/json']),
        ]);

        $org = Organisation::create([
            'name' => 'Riigimetsa Majandamise Keskus',
            'slug' => 'rmk',
            'registry_base_uri' => 'https://adr.rmk.ee/',
            'fetcher_type' => 'rmk',
        ]);

        $fetcher = new RMKFetcher($org);
        $callbackData = [];

        // Start from 411048, with maxFailures=2 so it stops after 411049 and one more failure
        $fetcher->enumerateForwards(411048, 2, function ($data) use (&$callbackData) {
            $callbackData[] = $data;
        });

        // Should have one callback call (for 411048)
        $this->assertCount(1, $callbackData);
        $this->assertEquals(411048, $callbackData[0]['data']['id']);
    }

    public function test_it_does_not_create_file_records_for_restricted_files()
    {
        $jsonResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_410948.json');

        Http::fake([
            'https://adr.rmk.ee/api/dokument/410948' => Http::response($jsonResponse, 200, ['Content-Type' => 'application/json']),
        ]);

        $org = Organisation::create([
            'name' => 'Riigimetsa Majandamise Keskus',
            'slug' => 'rmk',
            'registry_base_uri' => 'https://adr.rmk.ee/',
            'fetcher_type' => 'rmk',
        ]);

        $fetcher = new RMKFetcher($org);
        $fetcher->setDownloadFiles(true);
        $document = $fetcher->store(410948);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertCount(0, $document->files);
    }

    public function test_store_does_not_throw_when_a_single_file_returns_500()
    {
        // Document 412349 has 5 public files; the live API returns 500 for file 1750283
        // (OneDrive_2026-01-06.zip). store() should skip that file and persist the others
        // instead of letting the RequestException bubble up and fail the whole document.
        $jsonResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_412349.json');

        Storage::fake('r2');
        Http::fake([
            'https://adr.rmk.ee/api/dokument/412349' => Http::response($jsonResponse, 200, ['Content-Type' => 'application/json']),
            'https://adr.rmk.ee/api/fail/1750283' => Http::response('', 500),
            'https://adr.rmk.ee/api/fail/*' => Http::response('dummy', 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="dummy.bin"',
            ]),
        ]);

        $org = Organisation::create([
            'name' => 'Riigimetsa Majandamise Keskus',
            'slug' => 'rmk',
            'registry_base_uri' => 'https://adr.rmk.ee/',
            'fetcher_type' => 'rmk',
        ]);

        $fetcher = new RMKFetcher($org);
        $fetcher->setDownloadFiles(true);
        $document = $fetcher->store(412349);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'original_id' => '412349',
            'url' => 'https://adr.rmk.ee/dokument/412349',
        ]);
    }

    public function test_document_with_mixed_file_access_has_avalik_restriction()
    {
        // Document 411060 has doc_access=RESTRICTED but one file has file_access=PUBLIC
        // When any file is public, the document restriction should be "Avalik"
        $jsonResponse = file_get_contents(__DIR__ . '/../__fixtures/rmk_document_411060.json');

        Http::fake([
            'https://adr.rmk.ee/api/dokument/411060' => Http::response($jsonResponse, 200, ['Content-Type' => 'application/json']),
        ]);

        $org = Organisation::create([
            'name' => 'Riigimetsa Majandamise Keskus',
            'slug' => 'rmk',
            'registry_base_uri' => 'https://adr.rmk.ee/',
            'fetcher_type' => 'rmk',
        ]);

        $fetcher = new RMKFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(411060);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertEquals('Avalik', $document->restriction);
    }
}
