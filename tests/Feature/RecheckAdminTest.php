<?php

namespace Tests\Feature;

use App\Lib\Recheck\Heartbeat;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecheckAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::create(['name' => 'SOM', 'slug' => 'som', 'registry_base_uri' => 'https://adr.rik.ee/som/', 'fetcher_type' => 'delta-adr']);
    }

    private function doc(array $overrides = []): Document
    {
        $doc = Document::create(array_merge([
            'organisation_id' => $this->org->id,
            'url' => 'https://adr.rik.ee/som/dokument/' . random_int(1, 999999),
            'original_id' => '1',
            'title' => 'Pöördumine',
            'reference' => 'R',
            'registration_date' => '2024-01-01',
            'type' => 'Kiri',
            'restriction' => 'Avalik',
        ], $overrides));
        DocumentRemoteState::reconcile();
        return $doc;
    }

    private function change(Document $doc, array $overrides = []): DocumentStatusChange
    {
        return DocumentStatusChange::create(array_merge([
            'document_id' => $doc->id,
            'occurred_at' => now(),
            'from_status' => 'public',
            'to_status' => 'restricted',
            'from_restriction' => 'Avalik',
            'to_restriction' => 'AK',
            'basis' => 'AvTS § 35 lg 1 p 12',
            'personal_data' => true,
            'http_status' => 200,
        ], $overrides));
    }

    private function asAdmin(): static
    {
        return $this->withSession(['is_admin' => true]);
    }

    public function test_index_requires_admin(): void
    {
        $this->get(route('recheck.index'))->assertStatus(403);
        $this->post(route('recheck.acknowledge', $this->change($this->doc())))->assertStatus(403);
    }

    public function test_index_lists_unacknowledged_changes_and_health(): void
    {
        $doc = $this->doc();
        $open = $this->change($doc);
        $done = $this->change($doc, ['personal_data' => false, 'to_status' => 'gone', 'acknowledged_at' => now()]);
        Cache::forever(Heartbeat::KEY_BEAT, now()->toIso8601String());

        $response = $this->asAdmin()->get(route('recheck.index'));

        $response->assertOk()
            ->assertSee('Töötab')
            ->assertSee('Pöördumine')
            ->assertSee('isikuandmed (p 12)')
            ->assertSee('AvTS § 35 lg 1 p 12')
            ->assertSee('Peida')
            ->assertSee('Läbivaadatud');

        $this->assertTrue($response->viewData('changes')->contains($open));
        $this->assertFalse($response->viewData('changes')->contains($done));

        $all = $this->asAdmin()->get(route('recheck.index', ['all' => 1]));
        $this->assertTrue($all->viewData('changes')->contains($done));
    }

    public function test_index_shows_stopped_daemon_and_paused_hosts(): void
    {
        Cache::forever(Heartbeat::KEY_BEAT, now()->subHours(2)->toIso8601String());
        Cache::forever(Heartbeat::KEY_PAUSED, ['adr.politsei.ee' => now()->addDay()->toIso8601String()]);

        $this->asAdmin()->get(route('recheck.index'))
            ->assertOk()
            ->assertSee('Seiskunud')
            ->assertSee('adr.politsei.ee');
    }

    public function test_type_filter(): void
    {
        $doc = $this->doc();
        $personal = $this->change($doc);
        $gone = $this->change($doc, ['personal_data' => false, 'to_status' => 'gone', 'to_restriction' => null, 'basis' => null]);

        $response = $this->asAdmin()->get(route('recheck.index', ['type' => 'gone']));
        $this->assertTrue($response->viewData('changes')->contains($gone));
        $this->assertFalse($response->viewData('changes')->contains($personal));

        $response = $this->asAdmin()->get(route('recheck.index', ['type' => 'personal']));
        $this->assertTrue($response->viewData('changes')->contains($personal));
        $this->assertFalse($response->viewData('changes')->contains($gone));
    }

    public function test_acknowledge_and_ignore(): void
    {
        $doc = $this->doc();
        $a = $this->change($doc);
        $b = $this->change($doc);

        $this->asAdmin()->post(route('recheck.acknowledge', $a))->assertRedirect();
        $this->asAdmin()->post(route('recheck.ignore', $b))->assertRedirect();

        $this->assertNotNull($a->fresh()->acknowledged_at);
        $this->assertNull($a->fresh()->action);
        $this->assertSame(DocumentStatusChange::ACTION_IGNORED, $b->fresh()->action);
        $this->assertTrue($doc->fresh()->visible, 'acknowledging never changes visibility');
    }

    public function test_hide_makes_document_return_451_to_visitors_only(): void
    {
        $doc = $this->doc();
        $change = $this->change($doc);

        $this->get(route('document', ['document' => $doc->id]))->assertOk();

        $this->asAdmin()->post(route('recheck.hide', $change))->assertRedirect();

        $this->assertFalse($doc->fresh()->visible);
        $this->assertSame(DocumentStatusChange::ACTION_HIDDEN, $change->fresh()->action);
        $this->flushSession();
        $this->get(route('document', ['document' => $doc->id]))->assertStatus(451);
        $this->getJson("/api/documents/{$doc->id}")->assertStatus(451);
        $this->asAdmin()->get(route('document', ['document' => $doc->id]))->assertOk();
    }

    public function test_unhide_restores_visibility(): void
    {
        $doc = $this->doc(['visible' => false]);
        $change = $this->change($doc, ['from_status' => 'restricted', 'to_status' => 'public', 'personal_data' => false]);

        $this->asAdmin()->post(route('recheck.unhide', $change))->assertRedirect();

        $this->assertTrue($doc->fresh()->visible);
        $this->assertSame(DocumentStatusChange::ACTION_UNHIDDEN, $change->fresh()->action);
    }

    public function test_delete_files_removes_files_keeps_metadata_and_hides(): void
    {
        Storage::fake('r2');
        $doc = $this->doc();
        $file = File::create(['document_id' => $doc->id, 'name' => 'a.pdf', 'location' => 'x/a.pdf', 'contents' => 'text']);
        Storage::disk('r2')->put('x/a.pdf', 'bytes');
        $change = $this->change($doc);

        $this->asAdmin()->post(route('recheck.deleteFiles', $change))->assertRedirect();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        Storage::disk('r2')->assertMissing('x/a.pdf');
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'url' => $doc->url, 'visible' => false]);
        $this->assertSame(DocumentStatusChange::ACTION_FILES_DELETED, $change->fresh()->action);
    }

    public function test_refetch_downloads_files_again_and_unhides(): void
    {
        Storage::fake('r2');
        $doc = $this->doc(['url' => 'https://adr.rik.ee/som/dokument/18270648', 'original_id' => '18270648', 'visible' => false]);
        $change = $this->change($doc, ['from_status' => 'restricted', 'to_status' => 'public', 'to_restriction' => 'Avalik', 'personal_data' => false]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://adr.rik.ee/som/dokument/18270648') {
                return Http::response(file_get_contents(__DIR__ . '/../__fixtures/som_document_18270648.html'), 200);
            }
            return Http::response('Lepingu tekst', 200, ['Content-Disposition' => 'attachment; filename="leping.txt"']);
        });

        $this->asAdmin()->post(route('recheck.refetch', $change))->assertRedirect();

        $doc = $doc->fresh();
        $this->assertTrue($doc->visible);
        $this->assertGreaterThan(0, $doc->files()->count());
        $this->assertSame('leping.txt', $doc->files()->first()->name);
        $this->assertStringContainsString('Lepingu tekst', $doc->fresh()->file_contents);
        $this->assertSame(DocumentStatusChange::ACTION_REFETCHED, $change->fresh()->action);
    }

    public function test_acknowledge_all_respects_filter(): void
    {
        $doc = $this->doc();
        $personal = $this->change($doc);
        $gone = $this->change($doc, ['personal_data' => false, 'to_status' => 'gone']);

        $this->asAdmin()->post(route('recheck.acknowledgeAll'), ['type' => 'gone'])->assertRedirect();

        $this->assertNotNull($gone->fresh()->acknowledged_at);
        $this->assertNull($personal->fresh()->acknowledged_at);
    }

    public function test_migration_backfill_keeps_previously_hidden_documents_hidden(): void
    {
        // Simulate rows as they would exist before the migration ran, then
        // re-run the backfill statements against them.
        $hidden = $this->doc(['last_visibility' => 'AK', 'last_reason' => 'AvTS § 35 lg 1 p 12', 'last_audit_check_at' => '2025-01-10 10:00:00']);
        $unknown = $this->doc(['last_visibility' => 'Unknown', 'last_audit_check_at' => '2025-01-10 10:00:00']);
        $public = $this->doc(['last_visibility' => 'Avalik', 'last_audit_check_at' => '2025-01-10 10:00:00']);
        DocumentRemoteState::query()->delete();

        $migration = require base_path('database/migrations/2026_09_07_000001_create_document_remote_states_table.php');
        $migration->down();
        $migration->up();

        $this->assertFalse($hidden->fresh()->visible);
        $this->assertTrue($unknown->fresh()->visible);
        $this->assertTrue($public->fresh()->visible);

        $this->assertSame('restricted', DocumentRemoteState::find($hidden->id)->remote_status);
        $this->assertSame('AvTS § 35 lg 1 p 12', DocumentRemoteState::find($hidden->id)->remote_restriction_basis);
        $this->assertNotNull(DocumentRemoteState::find($hidden->id)->checked_at);
        $this->assertNull(DocumentRemoteState::find($unknown->id)->remote_status);
        $this->assertNull(DocumentRemoteState::find($unknown->id)->checked_at);
        $this->assertSame('public', DocumentRemoteState::find($public->id)->remote_status);
        $this->assertNull(DocumentRemoteState::find($public->id)->next_check_at, 'everything is due immediately after the migration');
    }
}
