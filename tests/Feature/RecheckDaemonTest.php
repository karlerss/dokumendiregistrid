<?php

namespace Tests\Feature;

use App\Lib\Recheck\Heartbeat;
use App\Mail\RecheckDigestMail;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecheckDaemonTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $som;
    private Organisation $rmk;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('recheck.per_host_delay_ms', 0);
        config()->set('recheck.global_max_rps', 0);
        config()->set('recheck.batch_size', 10);
        config()->set('recheck.host_error_threshold', 2);
        config()->set('mail.admin.address', 'admin@example.com');
        Mail::fake();

        $this->som = Organisation::create(['name' => 'SOM', 'slug' => 'som', 'registry_base_uri' => 'https://adr.rik.ee/som/', 'fetcher_type' => 'delta-adr']);
        $this->rmk = Organisation::create(['name' => 'RMK', 'slug' => 'rmk', 'registry_base_uri' => 'https://adr.rmk.ee', 'fetcher_type' => 'rmk']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function doc(Organisation $org, string $url, string $id, string $restriction = 'Avalik'): Document
    {
        return Document::create([
            'organisation_id' => $org->id,
            'url' => $url,
            'original_id' => $id,
            'title' => "Doc $id",
            'reference' => 'R',
            'registration_date' => '2024-01-01',
            'type' => 'Kiri',
            'restriction' => $restriction,
        ]);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../__fixtures/' . $name);
    }

    public function test_once_checks_every_due_public_document_and_records_results(): void
    {
        $public = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/18270648', '18270648');
        $restricted = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/18976496', '18976496');
        $gone = $this->doc($this->rmk, 'https://adr.rmk.ee/dokument/999', '999');
        $akAtIngest = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/3', '3', 'AK');

        Http::fake([
            $public->url => Http::response($this->fixture('som_document_18270648.html'), 200),
            $restricted->url => Http::response($this->fixture('adr_document_ak_18976496.html'), 200),
            'https://adr.rmk.ee/api/dokument/999' => Http::response('{"status":true,"data":false}', 200),
        ]);

        $this->artisan('app:recheck-daemon', ['--once' => true])
            ->assertSuccessful();

        Http::assertSentCount(3);

        $this->assertSame('public', DocumentRemoteState::find($public->id)->remote_status);
        $this->assertSame('restricted', DocumentRemoteState::find($restricted->id)->remote_status);
        $this->assertTrue(DocumentRemoteState::find($restricted->id)->personal_data_restriction);
        $this->assertSame('gone', DocumentRemoteState::find($gone->id)->remote_status);
        $this->assertNull(DocumentRemoteState::find($akAtIngest->id), 'restricted-at-ingest documents are ignored');

        $this->assertSame(2, DocumentStatusChange::count());
        $this->assertTrue(DocumentStatusChange::where('document_id', $restricted->id)->where('personal_data', true)->exists());
        $this->assertTrue(DocumentStatusChange::where('document_id', $gone->id)->where('to_status', 'gone')->exists());

        // Nothing visible changed.
        $this->assertSame(4, Document::where('visible', true)->count());

        // Heartbeat and counters written.
        $this->assertNotNull(Heartbeat::lastBeat());
        $stats = Heartbeat::statsForLastHours(1);
        $this->assertSame(3, $stats['checks']);
        $this->assertSame(2, $stats['changes']);
        $this->assertSame(1, $stats['personal_data']);
        $this->assertSame(0, $stats['errors']);
    }

    public function test_documents_that_are_not_due_are_skipped(): void
    {
        $doc = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/18270648', '18270648');
        DocumentRemoteState::reconcile();
        DocumentRemoteState::find($doc->id)->update(['next_check_at' => now()->addDay()]);
        Http::fake();

        $this->artisan('app:recheck-daemon', ['--once' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_limit_stops_early(): void
    {
        $this->doc($this->som, 'https://adr.rik.ee/som/dokument/1', '1');
        $this->doc($this->som, 'https://adr.rik.ee/som/dokument/2', '2');
        $this->doc($this->som, 'https://adr.rik.ee/som/dokument/3', '3');
        Http::fake(['*' => Http::response($this->fixture('som_document_18270648.html'), 200)]);

        $this->artisan('app:recheck-daemon', ['--once' => true, '--limit' => 2])->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(2, DocumentRemoteState::whereNotNull('checked_at')->count());
    }

    public function test_dry_run_probes_without_writing(): void
    {
        $doc = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/18976496', '18976496');
        Http::fake([$doc->url => Http::response($this->fixture('adr_document_ak_18976496.html'), 200)]);

        $this->artisan('app:recheck-daemon', ['--once' => true, '--dry-run' => true])
            ->expectsOutputToContain('restricted')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(0, DocumentRemoteState::count());
        $this->assertSame(0, DocumentStatusChange::count());
        $this->assertNull(Heartbeat::lastBeat());
    }

    public function test_specific_document_ids_are_checked_even_when_not_due(): void
    {
        $a = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/1', '1');
        $b = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/2', '2');
        DocumentRemoteState::reconcile();
        DocumentRemoteState::query()->update(['next_check_at' => now()->addDay()]);
        Http::fake(['*' => Http::response($this->fixture('som_document_18270648.html'), 200)]);

        $this->artisan('app:recheck-daemon', ['--document' => [$a->id]])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn($request) => $request->url() === $a->url);
        $this->assertNotNull(DocumentRemoteState::find($a->id)->checked_at);
        $this->assertNull(DocumentRemoteState::find($b->id)->checked_at);
    }

    public function test_transient_errors_back_off_and_pause_the_host_after_the_threshold(): void
    {
        $a = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/1', '1');
        $b = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/2', '2');
        $c = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/3', '3');
        $other = $this->doc($this->rmk, 'https://adr.rmk.ee/dokument/5', '5');

        Http::fake([
            'https://adr.rik.ee/*' => Http::response('down', 503),
            'https://adr.rmk.ee/api/dokument/5' => Http::response($this->fixture('rmk_document_411048.json'), 200),
        ]);

        $this->artisan('app:recheck-daemon', ['--once' => true])->assertSuccessful();

        // Threshold 2: a and b fail (2 attempts each because of the 5xx retry),
        // the host is paused, c is left untouched, the other host proceeds.
        $this->assertSame(1, DocumentRemoteState::find($a->id)->check_error_count);
        $this->assertSame(1, DocumentRemoteState::find($b->id)->check_error_count);
        $this->assertNull(DocumentRemoteState::find($a->id)->remote_status);
        $this->assertSame(0, DocumentRemoteState::find($c->id)->check_error_count);
        $this->assertNull(DocumentRemoteState::find($c->id)->next_check_at);
        $this->assertSame('public', DocumentRemoteState::find($other->id)->remote_status);
        $this->assertSame(0, DocumentStatusChange::count());
        $this->assertArrayHasKey('adr.rik.ee', Heartbeat::pausedHosts());
    }

    public function test_bot_check_pauses_the_host_at_once(): void
    {
        $ppa = Organisation::create(['name' => 'PPA', 'slug' => 'ppa', 'registry_base_uri' => 'https://adr.politsei.ee/ppa/', 'fetcher_type' => 'delta-adr']);
        $a = $this->doc($ppa, 'https://adr.politsei.ee/ppa/dokument/1', '1');
        $b = $this->doc($ppa, 'https://adr.politsei.ee/ppa/dokument/2', '2');
        Http::fake(['https://adr.politsei.ee/*' => Http::response('', 302, ['Location' => '/?returnUrl=%2Fppa%2Fdokument%2F1'])]);

        $this->artisan('app:recheck-daemon', ['--once' => true])->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, DocumentRemoteState::find($a->id)->check_error_count);
        $this->assertSame(0, DocumentRemoteState::find($b->id)->check_error_count);
        $paused = Heartbeat::pausedHosts();
        $this->assertArrayHasKey('adr.politsei.ee', $paused);
        $this->assertTrue($paused['adr.politsei.ee']->gt(now()->addHours(23)));
    }

    public function test_digest_is_mailed_once_per_day_when_there_are_new_changes(): void
    {
        Carbon::setTestNow('2026-09-07 09:00:00');
        $doc = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/18976496', '18976496');
        Http::fake([$doc->url => Http::response($this->fixture('adr_document_ak_18976496.html'), 200)]);

        $this->artisan('app:recheck-daemon', ['--once' => true])->assertSuccessful();

        Mail::assertSent(RecheckDigestMail::class, function (RecheckDigestMail $mail) {
            return $mail->hasTo('admin@example.com');
        });
        $this->assertSame('2026-09-07', Cache::get(Heartbeat::KEY_DIGEST_DATE));

        // Second run the same day: no second mail.
        DocumentRemoteState::query()->update(['next_check_at' => null]);
        $this->artisan('app:recheck-daemon', ['--once' => true])->assertSuccessful();
        Mail::assertSent(RecheckDigestMail::class, 1);
    }

    public function test_digest_is_not_sent_before_the_digest_hour_or_without_changes(): void
    {
        Carbon::setTestNow('2026-09-07 06:00:00');
        $doc = $this->doc($this->som, 'https://adr.rik.ee/som/dokument/18976496', '18976496');
        Http::fake([$doc->url => Http::response($this->fixture('adr_document_ak_18976496.html'), 200)]);
        $this->artisan('app:recheck-daemon', ['--once' => true])->assertSuccessful();
        Mail::assertNothingSent();

        Carbon::setTestNow('2026-09-08 09:00:00');
        Http::fake([$doc->url => Http::response($this->fixture('adr_document_ak_18976496.html'), 200)]);
        DocumentRemoteState::query()->update(['next_check_at' => null]);
        $this->artisan('app:recheck-daemon', ['--once' => true])->assertSuccessful();
        Mail::assertNothingSent();
    }
}
