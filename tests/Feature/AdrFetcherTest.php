<?php

namespace Tests\Feature;

use App\Lib\Fetcher\AdrFetcher;
use App\Models\Document;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdrFetcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_doc_fields_to_database()
    {
        // Recorded HTML response from https://adr.rik.ee/som/dokument/18270648
        $htmlResponse = file_get_contents(__DIR__ . '/../__fixtures/som_document_18270648.html');

        Http::fake([
            'https://adr.rik.ee/som/dokument/18270648' => Http::response($htmlResponse, 200),
        ]);

        $org = Organisation::create([
            'name' => 'Sotsiaalministeerium',
            'slug' => 'som',
            'registry_base_uri' => 'https://adr.rik.ee/som/',
            'fetcher_type' => 'delta-adr',
        ]);

        $fetcher = new AdrFetcher($org);
        $fetcher->setDownloadFiles(false);
        $document = $fetcher->store(18270648);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'organisation_id' => $org->id,
            'original_id' => '18270648',
            'url' => 'https://adr.rik.ee/som/dokument/18270648',
            'reference' => '5.1-2/3233-1',
            'type' => 'Sissetulev kiri',
            'title' => 'Pöördumine',
            'function' => '5.1 Tervisekaitse, haiguste ennetamise ja tervise edendamise korraldamine',
            'series' => '5.1-2 Tervisekaitse ja tervisliku elukeskkonna kavandamise ja korraldamisega seotud kirjavahetus (Arhiiviväärtuslik)',
            'dossier' => '5.1-2/2025',
            'restriction' => 'Avalik',
            'to' => 'Justiits- ja Digiministeerium',
            'method' => 'DVK',
            'responsible' => 'Anniki Lai (Sotsiaalministeerium, Kantsleri vastutusvaldkond, Terviseala asekantsleri vastutusvaldkond)',
        ]);

        $this->assertEquals('2025-12-23', $document->registration_date->format('Y-m-d'));
    }

    public function test_getting_paginated_results_for_single_date()
    {
        // Recorded responses from https://adr.rik.ee/som/otsing for 2025-12-01
        // Avalik: 22 results (page 1: 20, page 2: 2)
        // AK: 19 results (page 1: 19)
        // Total: 41 results

        $formPage = file_get_contents(__DIR__ . '/../__fixtures/som_otsing_form.html');
        $avalikPage1 = file_get_contents(__DIR__ . '/../__fixtures/som_otsing_avalik_page1.html');
        $avalikPage2 = file_get_contents(__DIR__ . '/../__fixtures/som_otsing_avalik_page2.html');
        $akPage1 = file_get_contents(__DIR__ . '/../__fixtures/som_otsing_ak_page1.html');

        Http::fake(function ($request) use ($formPage, $avalikPage1, $avalikPage2, $akPage1) {
            $url = $request->url();

            // GET request for form page (to get type count)
            if ($request->method() === 'GET' && str_contains($url, 'otsing')) {
                return Http::response($formPage, 200);
            }

            // POST requests for search results
            if ($request->method() === 'POST' && str_contains($url, 'otsing')) {
                $body = $request->body();

                if (str_contains($body, 'accessRestriction=Avalik')) {
                    if (str_contains($body, 'pageNumber=1')) {
                        return Http::response($avalikPage1, 200);
                    }
                    if (str_contains($body, 'pageNumber=2')) {
                        return Http::response($avalikPage2, 200);
                    }
                    // Page 3+ should not be called since page 2 has < 20 results
                    return Http::response('', 200);
                }

                if (str_contains($body, 'accessRestriction=AK')) {
                    if (str_contains($body, 'pageNumber=1')) {
                        return Http::response($akPage1, 200);
                    }
                    // Page 2+ should not be called since page 1 has < 20 results
                    return Http::response('', 200);
                }
            }

            return Http::response('', 404);
        });

        $org = Organisation::create([
            'name' => 'Sotsiaalministeerium',
            'slug' => 'som',
            'registry_base_uri' => 'https://adr.rik.ee/som/',
            'fetcher_type' => 'delta-adr',
        ]);

        $fetcher = new AdrFetcher($org);
        $date = Carbon::parse('2025-12-01');
        $results = $fetcher->list($date);

        $this->assertCount(41, $results);

        // Verify some specific document IDs from the results
        // From Avalik page 1
        $this->assertContains(18186998, $results);
        $this->assertContains(18186967, $results);
        // From Avalik page 2
        $this->assertContains(18186992, $results);
        $this->assertContains(18186995, $results);
        // From AK page 1
        $this->assertContains(18186904, $results);
        $this->assertContains(18186916, $results);
    }
}
