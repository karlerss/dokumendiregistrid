<?php

namespace Tests\Feature;

use App\Lib\Recheck\RemoteCheck;
use App\Models\Document;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * checkRemote() on every fetcher, driven by recorded registry responses.
 */
class RemoteCheckTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../__fixtures/' . $name);
    }

    private function makeOrg(string $type, string $base, string $slug = 'org'): Organisation
    {
        return Organisation::create([
            'name' => $slug,
            'slug' => $slug,
            'registry_base_uri' => $base,
            'fetcher_type' => $type,
        ]);
    }

    private function makeDoc(Organisation $org, string $url, string $originalId): Document
    {
        return Document::create([
            'organisation_id' => $org->id,
            'url' => $url,
            'original_id' => $originalId,
            'title' => 'Doc',
            'reference' => 'R-1',
            'registration_date' => '2026-01-01',
            'type' => 'Kiri',
            'restriction' => 'Avalik',
        ]);
    }

    // ------------------------------------------------------------------ ADR

    private function adrDoc(string $id = '18270648'): Document
    {
        $org = $this->makeOrg('delta-adr', 'https://adr.rik.ee/som/', 'som');
        return $this->makeDoc($org, "https://adr.rik.ee/som/dokument/$id", $id);
    }

    public function test_adr_public_document(): void
    {
        $doc = $this->adrDoc();
        Http::fake([$doc->url => Http::response($this->fixture('som_document_18270648.html'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::PUBLIC, $check->outcome);
        $this->assertSame('Avalik', $check->restriction);
        $this->assertSame([], $check->bases);
        $this->assertFalse($check->isPersonalData());
        $this->assertSame(200, $check->httpStatus);
    }

    public function test_adr_restricted_personal_data_document(): void
    {
        $doc = $this->adrDoc('18976496');
        Http::fake([$doc->url => Http::response($this->fixture('adr_document_ak_18976496.html'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::RESTRICTED, $check->outcome);
        $this->assertSame('AK', $check->restriction);
        $this->assertSame(['AvTS § 35 lg 1 p 12'], $check->bases);
        $this->assertTrue($check->isPersonalData());
        $this->assertNull($check->changeBasis);
    }

    public function test_adr_404_is_gone_and_not_retried(): void
    {
        $doc = $this->adrDoc();
        Http::fake([$doc->url => Http::response($this->fixture('adr_document_404.html'), 404)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::GONE, $check->outcome);
        $this->assertSame(404, $check->httpStatus);
        Http::assertSentCount(1);
    }

    public function test_adr_500_is_a_transient_error_after_retries(): void
    {
        $doc = $this->adrDoc();
        Http::fake([$doc->url => Http::response('boom', 500)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_HTTP_5XX, $check->errorKind);
        $this->assertSame(500, $check->httpStatus);
        Http::assertSentCount(2);
    }

    public function test_adr_timeout_is_a_transient_error(): void
    {
        $doc = $this->adrDoc();
        Http::fake([$doc->url => fn() => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds')]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_TIMEOUT, $check->errorKind);
        $this->assertNull($check->httpStatus);
    }

    public function test_adr_turnstile_redirect_is_a_bot_check_error(): void
    {
        $org = $this->makeOrg('delta-adr', 'https://adr.politsei.ee/ppa/', 'ppa');
        $doc = $this->makeDoc($org, 'https://adr.politsei.ee/ppa/dokument/17128098', '17128098');
        Http::fake([$doc->url => Http::response('', 302, ['Location' => '/?returnUrl=%2Fppa%2Fdokument%2F17128098'])]);

        $check = $org->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_BOT_CHECK, $check->errorKind);
        $this->assertTrue($check->isBotCheck());
        $this->assertSame(302, $check->httpStatus);
    }

    public function test_adr_turnstile_page_with_200_is_a_bot_check_error(): void
    {
        $doc = $this->adrDoc();
        Http::fake([$doc->url => Http::response($this->fixture('adr_turnstile.html'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_BOT_CHECK, $check->errorKind);
    }

    public function test_adr_unrelated_200_page_is_unparseable_not_gone(): void
    {
        $doc = $this->adrDoc();
        Http::fake([$doc->url => Http::response('<html><body><h1>Hooldustööd</h1></body></html>', 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_UNPARSEABLE, $check->errorKind);
    }

    public function test_adr_change_basis_is_captured(): void
    {
        $doc = $this->adrDoc('18976496');
        $html = str_replace(
            '<th class="nowrap">Juurdepääsupiirangu alus:</th>',
            '<th class="nowrap">Juurdepääsupiirangu muutmise alus:</th><td class="data">AvTS-i § 40 lg 1 alusel tähtaega pikendatakse</td></tr><tr><th class="nowrap">Juurdepääsupiirangu alus:</th>',
            $this->fixture('adr_document_ak_18976496.html')
        );
        Http::fake([$doc->url => Http::response($html, 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::RESTRICTED, $check->outcome);
        $this->assertSame('AvTS-i § 40 lg 1 alusel tähtaega pikendatakse', $check->changeBasis);
    }

    // ------------------------------------------------------------------ RMK

    private function rmkDoc(string $id): Document
    {
        $org = $this->makeOrg('rmk', 'https://adr.rmk.ee', 'rmk');
        return $this->makeDoc($org, "https://adr.rmk.ee/dokument/$id", $id);
    }

    public function test_rmk_public_document(): void
    {
        $doc = $this->rmkDoc('411048');
        Http::fake(['https://adr.rmk.ee/api/dokument/411048' => Http::response($this->fixture('rmk_document_411048.json'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::PUBLIC, $check->outcome);
    }

    public function test_rmk_restricted_document(): void
    {
        $doc = $this->rmkDoc('410948');
        Http::fake(['https://adr.rmk.ee/api/dokument/410948' => Http::response($this->fixture('rmk_document_410948.json'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::RESTRICTED, $check->outcome);
        $this->assertSame('AK', $check->restriction);
        $this->assertNotEmpty($check->bases);
    }

    public function test_rmk_missing_document_is_gone(): void
    {
        $doc = $this->rmkDoc('999999999');
        Http::fake(['https://adr.rmk.ee/api/dokument/999999999' => Http::response('{"status":true,"data":false,"message":"Dokumenti ei leitud!"}', 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::GONE, $check->outcome);
        $this->assertSame(200, $check->httpStatus);
    }

    public function test_rmk_non_json_is_unparseable(): void
    {
        $doc = $this->rmkDoc('1');
        Http::fake(['https://adr.rmk.ee/api/dokument/1' => Http::response('<html>maintenance</html>', 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_UNPARSEABLE, $check->errorKind);
    }

    // -------------------------------------------------------------- Tallinn

    private function tallinnDoc(string $id): Document
    {
        $org = $this->makeOrg('tallinn-atp', 'https://dhs.tallinn.ee/atp/', 'tallinn');
        return $this->makeDoc($org, "https://dhs.tallinn.ee/atp/?c_tpl=1092&command=details&dok_id=$id", $id);
    }

    public function test_tallinn_public_document(): void
    {
        $doc = $this->tallinnDoc('5709346');
        Http::fake([$doc->url => Http::response($this->fixture('tallinn_document_5709346.html'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertContains($check->outcome, [RemoteCheck::PUBLIC, RemoteCheck::RESTRICTED]);
        $this->assertNotNull($check->restriction);
    }

    public function test_tallinn_missing_document_is_gone(): void
    {
        $doc = $this->tallinnDoc('999999999');
        Http::fake([$doc->url => Http::response($this->fixture('tallinn_document_missing.html'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::GONE, $check->outcome);
    }

    public function test_tallinn_page_without_container_is_unparseable(): void
    {
        $doc = $this->tallinnDoc('1');
        Http::fake([$doc->url => Http::response('<html><body>Hooldus</body></html>', 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_UNPARSEABLE, $check->errorKind);
    }

    // -------------------------------------------------------- Riigikantselei

    private function rkDoc(string $noteId, int $id): Document
    {
        $org = $this->makeOrg('riigikantselei-dhs', 'https://dhs.riigikantselei.ee/avalikteave.nsf/', 'riigikantselei');
        return $this->makeDoc($org, "https://dhs.riigikantselei.ee/avalikteave.nsf/documents/$noteId?open", (string)$id);
    }

    public function test_riigikantselei_public_document(): void
    {
        $doc = $this->rkDoc('NT00415122', hexdec('00415122'));
        Http::fake([$doc->url => Http::response($this->fixture('rk_document_NT00415122.xml'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::PUBLIC, $check->outcome);
    }

    public function test_riigikantselei_restricted_document(): void
    {
        $doc = $this->rkDoc('NT00415146', hexdec('00415146'));
        Http::fake([$doc->url => Http::response($this->fixture('rk_document_NT00415146.xml'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::RESTRICTED, $check->outcome);
        $this->assertSame('Asutusesiseseks kasutamiseks', $check->restriction);
        $this->assertStringStartsWith('AvTS § 35 lg 2 p 1', $check->bases[0]);
        $this->assertFalse($check->isPersonalData());
    }

    public function test_riigikantselei_404_is_gone(): void
    {
        $doc = $this->rkDoc('NT00000001', 1);
        Http::fake([$doc->url => Http::response($this->fixture('rk_document_404.html'), 404)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::GONE, $check->outcome);
        $this->assertSame(404, $check->httpStatus);
    }

    // ------------------------------------------------------------ Riigikogu

    private function riigikoguDoc(string $uuid): Document
    {
        $org = $this->makeOrg('riigikogu', 'https://www.riigikogu.ee/tegevus/dokumendiregister/', 'riigikogu');
        return $this->makeDoc($org, "https://www.riigikogu.ee/tegevus/dokumendiregister/dokument/$uuid/", $uuid);
    }

    public function test_riigikogu_public_document(): void
    {
        $doc = $this->riigikoguDoc('5a0c8190-0000-0000-0000-000000000000');
        Http::fake([$doc->url => Http::response($this->fixture('riigikogu_document_5a0c8190.html'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::PUBLIC, $check->outcome);
        $this->assertSame('Avalik', $check->restriction);
    }

    public function test_riigikogu_unknown_uuid_is_gone(): void
    {
        $doc = $this->riigikoguDoc('00000000-0000-0000-0000-000000000000');
        Http::fake([$doc->url => Http::response($this->fixture('riigikogu_document_missing.html'), 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::GONE, $check->outcome);
    }

    public function test_riigikogu_page_without_content_section_is_unparseable(): void
    {
        $doc = $this->riigikoguDoc('00000000-0000-0000-0000-000000000000');
        Http::fake([$doc->url => Http::response('<html><body>Just a moment...</body></html>', 200)]);

        $check = $doc->organisation->getFetcher()->checkRemote($doc);

        $this->assertSame(RemoteCheck::ERROR, $check->outcome);
        $this->assertSame(RemoteCheck::ERROR_BOT_CHECK, $check->errorKind);
    }
}
