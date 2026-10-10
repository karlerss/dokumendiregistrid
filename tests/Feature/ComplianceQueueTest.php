<?php

namespace Tests\Feature;

use App\Lib\Compliance\ReviewQueue;
use App\Mcp\Servers\ComplianceServer;
use App\Mcp\Tools\CheckExposureTool;
use App\Mcp\Tools\DecideTool;
use App\Mcp\Tools\FlagTool;
use App\Mcp\Tools\GetItemTool;
use App\Mcp\Tools\ListQueueTool;
use App\Mcp\Tools\ResolveUrlsTool;
use App\Models\ComplianceAction;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\Organisation;
use App\Models\PiiExtraction;
use App\Models\PiiSubject;
use App\Models\ReviewItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class ComplianceQueueTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['compliance.admin_token' => 'secret-token', 'app.url' => 'https://dokumendiregistrid.karlerss.com']);
        Storage::fake('r2');
        Storage::fake('r2_private');
        $this->org = Organisation::create(['name' => 'RMK', 'slug' => 'rmk', 'registry_base_uri' => 'https://adr.rmk.ee/', 'fetcher_type' => 'rmk']);
    }

    private function doc(array $overrides = []): Document
    {
        return Document::create(array_merge([
            'organisation_id' => $this->org->id,
            'url' => 'https://adr.rmk.ee/dokument/' . random_int(1, 999999),
            'original_id' => (string)random_int(1, 999999),
            'title' => 'Ametiauto parkimiskoha määramine',
            'reference' => '1-5/123',
            'registration_date' => '2014-02-25',
            'type' => 'Käskkiri',
            'restriction' => 'Avalik',
            'dossier' => 'kaskkirjad-2014',
        ], $overrides));
    }

    private function file(Document $doc, string $name = 'kaskkiri.pdf', array $overrides = []): File
    {
        $key = 'Key' . random_int(100000, 999999) . "/$name";
        Storage::disk('r2')->put($key, 'pdf');
        $file = File::create(array_merge([
            'document_id' => $doc->id,
            'name' => $name,
            'location' => $key,
            'contents' => 'Mari Maasikas, Tamme tn 5',
        ], $overrides));
        $doc->ftsIndexSingle();
        return $file;
    }

    /** Run a tool and return its structured result. */
    private function tool(string $tool, array $args): array
    {
        $out = null;
        ComplianceServer::tool($tool, $args)
            ->assertHasNoErrors()
            ->assertStructuredContent(function (AssertableJson $json) use (&$out) {
                $out = $json->toArray();
                $json->etc();
            });
        return $out;
    }

    // --- visibility on every public surface -------------------------------

    public function test_hidden_documents_stay_off_every_listing(): void
    {
        $shown = $this->doc(['title' => 'Avalik kaskkiri']);
        $hidden = $this->doc(['title' => 'Varjatud kaskkiri', 'visible' => false]);
        $this->file($shown);
        $this->file($hidden);

        $this->get('/?query=kaskkiri')->assertOk()->assertSee('Avalik kaskkiri')->assertDontSee('Varjatud kaskkiri');
        $this->get(route('dossier', 'kaskkirjad-2014'))->assertOk()->assertSee('Avalik kaskkiri')->assertDontSee('Varjatud kaskkiri');
        $this->get(route('archiveMonth', ['rmk', 2014, 2]))->assertOk()->assertSee('Avalik kaskkiri')->assertDontSee('Varjatud kaskkiri');
        $this->get('/sitemaps/rmk/2014.xml')->assertOk()->assertSee("/dokumendid/{$shown->id}/", false)->assertDontSee("/dokumendid/{$hidden->id}/", false);

        $api = $this->withHeader('User-Agent', 'test')->getJson('/api/documents?query=kaskkiri')->assertOk();
        $this->assertSame([$shown->id], array_column($api->json('data'), 'id'));

        // Admins still find hidden documents.
        $this->withSession(['is_admin' => true])->get('/?query=kaskkiri')->assertSee('Varjatud kaskkiri');
    }

    public function test_removed_document_returns_410(): void
    {
        $doc = $this->doc(['visible' => false, 'removed_at' => now()]);

        $this->get(route('document', $doc))->assertStatus(410);
        $this->withHeader('User-Agent', 'test')->getJson("/api/documents/{$doc->id}")->assertStatus(410);
    }

    public function test_removed_documents_are_not_rechecked(): void
    {
        $kept = $this->doc();
        $this->doc(['visible' => false, 'removed_at' => now()]);

        $this->assertSame([$kept->id], Document::query()->recheckable()->pluck('id')->all());
    }

    // --- endpoint auth -------------------------------------------------------

    private function rpc(?string $token)
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        if ($token !== null) {
            $headers['Authorization'] = "Bearer $token";
        }
        return $this->withHeaders($headers)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [],
        ]);
    }

    public function test_endpoint_requires_the_admin_token_for_everything(): void
    {
        $this->rpc(null)->assertStatus(401);
        $this->rpc('wrong')->assertStatus(401);

        $this->rpc('secret-token')->assertOk()->assertSee('decide')->assertSee('list_queue');
    }

    public function test_repeated_bad_tokens_lock_the_ip_out(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rpc('wrong')->assertStatus(401);
        }
        $this->rpc('secret-token')->assertStatus(429);
    }

    public function test_endpoint_is_closed_when_no_token_is_configured(): void
    {
        config(['compliance.admin_token' => null]);
        $this->rpc('')->assertStatus(401);
    }

    // --- flagging ------------------------------------------------------------

    public function test_flag_resolves_page_file_and_orphan_urls_into_one_item_per_document(): void
    {
        $doc = $this->doc();
        $file = $this->file($doc, 'doc0155.pdf');
        Storage::disk('r2')->put('Orphan123/lisa.xlsx', 'x');

        $out = $this->tool(FlagTool::class, [
            'reference' => 'AKI 2.1-1/26/123-4',
            'public_sector' => true,
            'personal_data' => true,
            'urls' => [
                "https://dokumendiregistrid.karlerss.com/dokumendid/{$doc->id}/ametiauto",
                'https://adr-docs.karlerss.com/' . str_replace(' ', '%20', $file->location),
                'https://adr-docs.karlerss.com/Orphan123/lisa.xlsx',
                'https://adr-docs.karlerss.com/Gone999/missing.pdf',
            ],
        ]);

        $this->assertSame(3, $out['flagged']);
        $this->assertSame(1, $out['unresolved']);
        $this->assertSame(['document', 'file', 'orphan_object', 'unknown'], array_column($out['results'], 'kind'));
        $this->assertSame($out['results'][0]['item_id'], $out['results'][1]['item_id']);

        $item = ReviewItem::where('document_id', $doc->id)->sole();
        $this->assertSame(ReviewItem::PRIORITY_NAMED, $item->priority);
        $this->assertCount(2, $item->flags);
        $this->assertTrue(ReviewItem::whereNull('document_id')->where('file_key', 'Orphan123/lisa.xlsx')->exists());

        // Nothing public changed.
        $this->assertTrue($doc->fresh()->visible);
        Storage::disk('r2')->assertExists($file->location);
    }

    public function test_flagging_the_same_letter_twice_is_a_no_op(): void
    {
        $doc = $this->doc();
        $args = ['reference' => 'AKI 01.06', 'document_ids' => [$doc->id], 'public_sector' => true, 'personal_data' => true];

        $this->tool(FlagTool::class, $args);
        $this->tool(FlagTool::class, $args);

        $this->assertCount(1, ReviewItem::sole()->flags);
    }

    public function test_list_queue_orders_by_priority_and_filters_by_reference(): void
    {
        $queue = app(ReviewQueue::class);
        $gone = $queue->flag($this->doc(), null, ReviewItem::SOURCE_RECHECK, ReviewItem::PRIORITY_GONE, null, 'gone');
        $named = $queue->flag($this->doc(), null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'AKI 123', null);

        $out = $this->tool(ListQueueTool::class, []);
        $this->assertSame([$named->id, $gone->id], array_column($out['items'], 'item_id'));

        $out = $this->tool(ListQueueTool::class, ['reference' => 'AKI 123']);
        $this->assertSame([$named->id], array_column($out['items'], 'item_id'));

        $out = $this->tool(ListQueueTool::class, ['source' => 'recheck']);
        $this->assertSame([$gone->id], array_column($out['items'], 'item_id'));
    }

    public function test_flag_requires_requester_and_personal_data_facts(): void
    {
        ComplianceServer::tool(FlagTool::class, ['reference' => 'x', 'document_ids' => [$this->doc()->id]])
            ->assertHasErrors(['public sector', 'personal data']);
    }

    public function test_list_queue_filters_personal_data_and_public_sector(): void
    {
        $queue = app(ReviewQueue::class);
        $aki = $queue->flag($this->doc(), null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'AKI', null, ['public_sector' => true, 'personal_data' => true]);
        $person = $queue->flag($this->doc(), null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'kiri', null, ['public_sector' => false, 'personal_data' => true]);
        $agencyOther = $queue->flag($this->doc(), null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'RIK', null, ['public_sector' => true, 'personal_data' => false]);

        // Daemon flag: no requester; personal data only via the source's restriction.
        $restrictedDoc = $this->doc();
        DocumentRemoteState::reconcile();
        DocumentRemoteState::where('document_id', $restrictedDoc->id)->update(['remote_status' => 'restricted', 'personal_data_restriction' => true]);
        $daemon = $queue->flag($restrictedDoc, null, ReviewItem::SOURCE_RECHECK, ReviewItem::PRIORITY_NAMED, null, 'restricted');

        $ids = fn(array $args) => array_column($this->tool(ListQueueTool::class, $args)['items'], 'item_id');

        $this->assertEqualsCanonicalizing([$aki->id, $person->id, $daemon->id], $ids(['personal_data' => true]));
        $this->assertEqualsCanonicalizing([$agencyOther->id], $ids(['personal_data' => false]));
        $this->assertEqualsCanonicalizing([$aki->id, $agencyOther->id], $ids(['public_sector' => true]));
        $this->assertEqualsCanonicalizing([$person->id], $ids(['public_sector' => false]));
        $this->assertEqualsCanonicalizing([$person->id], $ids(['public_sector' => false, 'personal_data' => true]));

        // A private person joining an agency's item makes it match both.
        $queue->flag($agencyOther->document, null, ReviewItem::SOURCE_TAKEDOWN, ReviewItem::PRIORITY_NAMED, 'takedown t1', null, ['public_sector' => false, 'personal_data' => true]);
        $this->assertContains($agencyOther->id, $ids(['public_sector' => false]));
        $this->assertContains($agencyOther->id, $ids(['public_sector' => true]));

        $row = collect($this->tool(ListQueueTool::class, ['personal_data' => true])['items'])->firstWhere('item_id', $daemon->id);
        $this->assertTrue($row['personal_data']);
        $this->assertSame(['public_sector' => false, 'private' => false], $row['requesters']);
    }

    // --- deciding ------------------------------------------------------------

    private function flagged(Document $doc): ReviewItem
    {
        return app(ReviewQueue::class)->flag($doc, null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'AKI 123', null);
    }

    public function test_remove_requires_a_dry_run_and_its_plan_id(): void
    {
        $doc = $this->doc();
        $file = $this->file($doc);
        $item = $this->flagged($doc);

        $plan = $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'Source removed it; personal data.']);
        $this->assertTrue($plan['dry_run']);
        $this->assertSame(["r2:{$file->location}"], $plan['effects']['files'][0]['objects']);
        Storage::disk('r2')->assertExists($file->location);

        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'test reason', 'confirm' => true])
            ->assertHasErrors(['plan_id']);
        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'test reason', 'confirm' => true, 'plan_id' => 'stale'])
            ->assertHasErrors(['changed']);

        $this->assertTrue($item->fresh()->isOpen());
        Storage::disk('r2')->assertExists($file->location);
    }

    public function test_remove_deletes_every_stored_copy_and_leaves_an_identifying_row(): void
    {
        $doc = $this->doc(['ai_summary' => 'Kokkuvõte', 'ai_title' => 'Pealkiri']);
        $withheld = $this->file($doc, 'a.pdf');
        Storage::disk('r2_private')->put('Priv/a.pdf', 'orig');
        Storage::disk('r2')->put('Red/a.pdf', 'red');
        $withheld->update(['original_withheld' => true, 'original_private_location' => 'Priv/a.pdf', 'redacted_location' => 'Red/a.pdf']);
        $plain = $this->file($doc, 'b.pdf');
        $child = $this->file($doc, 'c.pdf', ['parent_id' => $plain->id]);
        $extraction = PiiExtraction::create(['document_id' => $doc->id, 'model' => 'test', 'status' => 'done', 'prompt_version' => 1, 'schema_version' => 1, 'requested_by' => 'worker']);
        PiiSubject::create(['extraction_id' => $extraction->id, 'document_id' => $doc->id, 'name' => 'Mari Maasikas', 'context' => 'private_person']);
        DocumentRemoteState::reconcile();
        $change = DocumentStatusChange::create(['document_id' => $doc->id, 'occurred_at' => now(), 'to_status' => 'gone', 'http_status' => 404]);
        $item = $this->flagged($doc);

        $plan = $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'AKI order']);
        $done = $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'AKI order', 'reference' => 'AKI 123', 'confirm' => true, 'plan_id' => $plan['plan_id']]);

        $this->assertFalse($done['dry_run']);
        $this->assertSame('remove', $done['decision']);
        Storage::disk('r2')->assertMissing($plain->location);
        Storage::disk('r2')->assertMissing($child->location);
        Storage::disk('r2')->assertMissing('Red/a.pdf');
        Storage::disk('r2_private')->assertMissing('Priv/a.pdf');
        $this->assertSame(0, File::where('document_id', $doc->id)->count());
        $this->assertSame(0, PiiSubject::where('document_id', $doc->id)->count());
        $this->assertSame(0, PiiExtraction::where('document_id', $doc->id)->count());

        $doc->refresh();
        $this->assertTrue($doc->isRemoved());
        $this->assertFalse($doc->visible);
        $this->assertNull($doc->file_contents);
        $this->assertNull($doc->ai_summary);
        $this->assertSame($doc->original_id, Document::find($doc->id)->original_id);
        $this->assertSame('removed', $change->fresh()->action);
        $this->assertNotNull($change->fresh()->acknowledged_at);

        $this->get(route('document', $doc))->assertStatus(410);
        $this->get('/?query=Maasikas')->assertDontSee('Ametiauto');
        $this->assertTrue(ComplianceAction::where('review_item_id', $item->id)->where('effect', 'decided_remove')->exists());
        $cdn = ComplianceAction::where('review_item_id', $item->id)->where('effect', 'cdn_purged')->sole()->result;
        $this->assertTrue($cdn['skipped'], 'no Cloudflare credentials in tests');
        // Two root files; the child goes with its parent.
        $this->assertSame(2, ComplianceAction::where('review_item_id', $item->id)->where('effect', 'file_deleted')->count());

        // A removed document is never queued again.
        $again = app(ReviewQueue::class)->flag($doc, null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'AKI 02.06', null);
        $this->assertSame($item->id, $again->id);
        $this->assertSame(ReviewItem::STATUS_DECIDED, $again->fresh()->status);
    }

    public function test_tombstone_can_drop_the_descriptive_text(): void
    {
        config(['compliance.tombstone_keep_text' => false]);
        $doc = $this->doc(['to' => 'Mari Maasikas', 'responsible' => 'Jaan Tamm']);
        $item = $this->flagged($doc);

        $plan = $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'test reason']);
        $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'test reason', 'confirm' => true, 'plan_id' => $plan['plan_id']]);

        $doc->refresh();
        $this->assertSame('[eemaldatud]', $doc->title);
        $this->assertNull($doc->to);
        $this->assertNull($doc->responsible);
        $this->assertSame('1-5/123', $doc->reference);
    }

    public function test_keep_changes_nothing_public_and_a_new_flag_reopens_it(): void
    {
        $doc = $this->doc();
        $file = $this->file($doc);
        DocumentRemoteState::reconcile();
        $change = DocumentStatusChange::create(['document_id' => $doc->id, 'occurred_at' => now(), 'to_status' => 'gone', 'http_status' => 404]);
        $item = $this->flagged($doc);

        $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'keep', 'reason' => 'Source archived the series; no personal data in the document.']);

        $this->assertTrue($doc->fresh()->visible);
        Storage::disk('r2')->assertExists($file->location);
        $this->assertSame('kept', $change->fresh()->action);
        $this->assertSame(ReviewItem::DECISION_KEEP, $item->fresh()->decision);

        app(ReviewQueue::class)->flag($doc, null, ReviewItem::SOURCE_TAKEDOWN, ReviewItem::PRIORITY_NAMED, 'takedown abc', null);

        $item->refresh();
        $this->assertTrue($item->isOpen());
        $this->assertNull($item->decision);
        $this->assertTrue(ComplianceAction::where('review_item_id', $item->id)->where('effect', 'decided_keep')->exists());
        $this->assertTrue(ComplianceAction::where('review_item_id', $item->id)->where('effect', 'reopened')->exists());
    }

    public function test_decisions_need_an_open_item(): void
    {
        $item = $this->flagged($this->doc());

        $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'keep', 'reason' => 'test reason']);
        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'keep', 'reason' => 'test reason'])
            ->assertHasErrors(['already decided']);
    }

    public function test_remove_on_an_orphan_object_deletes_it(): void
    {
        Storage::disk('r2')->put('Orphan123/lisa.xlsx', 'x');
        $item = app(ReviewQueue::class)->flag(null, 'Orphan123/lisa.xlsx', ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'AKI 456', null);

        $plan = $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'erasure request']);
        $this->tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'erasure request', 'confirm' => true, 'plan_id' => $plan['plan_id']]);

        Storage::disk('r2')->assertMissing('Orphan123/lisa.xlsx');
        $this->assertTrue(ComplianceAction::where('effect', 'orphan_object_deleted')->where('file_key', 'Orphan123/lisa.xlsx')->exists());
    }

    public function test_a_removed_document_cannot_be_shown_again_or_deleted(): void
    {
        $doc = $this->doc(['visible' => false, 'removed_at' => now()]);

        try {
            $doc->update(['visible' => true]);
            $this->fail('unhiding a removed document must throw');
        } catch (\RuntimeException) {
        }
        try {
            $doc->fresh()->fullDelete();
            $this->fail('deleting a removed document must throw');
        } catch (\RuntimeException) {
        }
        $this->expectException(\RuntimeException::class);
        $doc->fresh()->reindex();
    }

    public function test_remove_waits_for_a_running_redaction(): void
    {
        $doc = $this->doc();
        \App\Models\PiiRedaction::create(['document_id' => $doc->id, 'applied_by' => 'admin', 'plan' => [], 'text_status' => 'applied', 'files_status' => 'pending']);
        $item = $this->flagged($doc);

        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'remove', 'reason' => 'test reason'])
            ->assertHasErrors(['still running']);
    }

    public function test_client_ip_trusts_cf_header_only_from_cloudflare(): void
    {
        $viaCf = \Illuminate\Http\Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => '188.114.97.1', 'HTTP_CF_CONNECTING_IP' => '90.191.1.2']);
        $direct = \Illuminate\Http\Request::create('/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '90.191.1.2']);

        $this->assertSame('90.191.1.2', \App\Support\ClientIp::of($viaCf));
        $this->assertSame('203.0.113.9', \App\Support\ClientIp::of($direct));
    }

    // --- reading -------------------------------------------------------------

    public function test_get_item_and_resolve_urls_describe_the_document(): void
    {
        $doc = $this->doc();
        $this->file($doc);
        $item = $this->flagged($doc);

        $out = $this->tool(GetItemTool::class, ['item_id' => $item->id]);
        $this->assertSame($doc->id, $out['document']['id']);
        $this->assertSame('kaskkiri.pdf', $out['files'][0]['name']);
        $this->assertSame(['AKI 123'], $out['references']);

        $out = $this->tool(ResolveUrlsTool::class, ['urls' => [$doc->url]]);
        $this->assertSame('document', $out['results'][0]['kind']);
        $this->assertSame($item->id, $out['results'][0]['review_item']['item_id']);
    }

    public function test_check_exposure_probes_page_and_objects(): void
    {
        $doc = $this->doc();
        $file = $this->file($doc, 'a b.pdf');
        Http::fake([
            'dokumendiregistrid.karlerss.com/*' => Http::response('', 200),
            'adr-docs.karlerss.com/*' => Http::response('', 404),
        ]);

        $out = $this->tool(CheckExposureTool::class, ['document_ids' => [$doc->id]]);

        $this->assertSame(1, $out['exposed']);
        $this->assertSame(200, $out['documents'][0]['page']['http']);
        $this->assertSame(404, $out['documents'][0]['objects'][0]['http']);
        $this->assertStringEndsWith('/a%20b.pdf', $out['documents'][0]['objects'][0]['url']);
        Http::assertSent(fn($r) => $r->method() === 'GET' && $r->hasHeader('Range', 'bytes=0-0') && $r->url() === "https://dokumendiregistrid.karlerss.com/dokumendid/{$doc->id}");
    }

    public function test_check_exposure_counts_a_file_cloudflare_still_serves_from_cache(): void
    {
        $doc = $this->doc(['visible' => false]);
        $this->file($doc, 'a.pdf');
        Http::fake([
            'dokumendiregistrid.karlerss.com/*' => Http::response('', 451),
            'adr-docs.karlerss.com/*' => Http::response('%', 206, ['cf-cache-status' => 'HIT']),
        ]);

        $out = $this->tool(CheckExposureTool::class, ['document_ids' => [$doc->id]]);

        $this->assertSame(1, $out['exposed']);
        $this->assertSame(206, $out['documents'][0]['objects'][0]['http']);
        $this->assertSame('HIT', $out['documents'][0]['objects'][0]['cache']);
    }
}
