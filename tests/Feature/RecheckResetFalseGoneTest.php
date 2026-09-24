<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecheckResetFalseGoneTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::create(['name' => 'SOM', 'slug' => 'som', 'registry_base_uri' => 'https://adr.rik.ee/som/', 'fetcher_type' => 'delta-adr']);
    }

    private function goneDoc(int $httpStatus, bool $acknowledged = false, ?int $stateStatus = null): Document
    {
        $doc = Document::create([
            'organisation_id' => $this->org->id,
            'url' => 'https://adr.rik.ee/som/dokument/' . random_int(1, 999999),
            'original_id' => '1',
            'title' => 'Pöördumine',
            'reference' => 'R',
            'registration_date' => '2024-01-01',
            'type' => 'Kiri',
            'restriction' => 'Avalik',
        ]);
        DocumentRemoteState::reconcile();
        DocumentRemoteState::query()->where('document_id', $doc->id)->update([
            'remote_status' => 'gone',
            'checked_at' => now(),
            'next_check_at' => now()->addDays(90),
            'last_http_status' => $stateStatus ?? $httpStatus,
        ]);
        DocumentStatusChange::create([
            'document_id' => $doc->id,
            'occurred_at' => now(),
            'from_status' => null,
            'to_status' => 'gone',
            'http_status' => $httpStatus,
            'acknowledged_at' => $acknowledged ? now() : null,
        ]);
        return $doc;
    }

    public function test_resets_unreviewed_429_and_403_verdicts_and_requeues_the_documents(): void
    {
        $rateLimited = $this->goneDoc(429);
        $forbidden = $this->goneDoc(403);
        $reviewed = $this->goneDoc(429, acknowledged: true);
        $genuine = $this->goneDoc(404);
        // Marked gone on 429 but a later probe (200) already superseded it.
        $superseded = $this->goneDoc(429, stateStatus: 200);

        $this->artisan('app:recheck-reset-false-gone')
            ->expectsOutputToContain('3 unreviewed "gone" row(s) on HTTP 429/403 across 3 document(s); 2 state row(s) to reset; 1 reviewed row(s) left untouched.')
            ->assertSuccessful();

        foreach ([$rateLimited, $forbidden] as $doc) {
            $state = DocumentRemoteState::query()->findOrFail($doc->id);
            $this->assertNull($state->remote_status);
            $this->assertNull($state->next_check_at);
            $this->assertNull($state->last_http_status);
            $this->assertSame(0, DocumentStatusChange::query()->where('document_id', $doc->id)->count());
        }

        $this->assertSame('gone', DocumentRemoteState::query()->findOrFail($reviewed->id)->remote_status);
        $this->assertSame(1, DocumentStatusChange::query()->where('document_id', $reviewed->id)->count());

        $this->assertSame('gone', DocumentRemoteState::query()->findOrFail($genuine->id)->remote_status);
        $this->assertSame(1, DocumentStatusChange::query()->where('document_id', $genuine->id)->count());

        $this->assertSame('gone', DocumentRemoteState::query()->findOrFail($superseded->id)->remote_status);
        $this->assertSame(0, DocumentStatusChange::query()->where('document_id', $superseded->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $doc = $this->goneDoc(429);

        $this->artisan('app:recheck-reset-false-gone --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame('gone', DocumentRemoteState::query()->findOrFail($doc->id)->remote_status);
        $this->assertSame(1, DocumentStatusChange::query()->count());
    }
}
