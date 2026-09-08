<?php

namespace Tests\Feature;

use App\Lib\Recheck\CheckPolicy;
use App\Lib\Recheck\DocumentChecker;
use App\Lib\Recheck\RemoteCheck;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\Organisation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-09-07 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checker(): DocumentChecker
    {
        return new DocumentChecker(new CheckPolicy([
            'intervals' => ['takedown' => 7, 'recent' => 14, 'recent_age_days' => 180, 'old' => 45, 'changed' => 90],
            'error_backoff_hours' => [1, 6, 24, 72],
        ]));
    }

    private function doc(array $overrides = []): Document
    {
        $org = Organisation::create([
            'name' => 'SOM', 'slug' => 'som', 'registry_base_uri' => 'https://adr.rik.ee/som/', 'fetcher_type' => 'delta-adr',
        ]);
        $doc = Document::create(array_merge([
            'organisation_id' => $org->id,
            'url' => 'https://adr.rik.ee/som/dokument/18270648',
            'original_id' => '18270648',
            'title' => 'Pöördumine',
            'reference' => 'R',
            'registration_date' => '2024-01-01',
            'type' => 'Kiri',
            'restriction' => 'Avalik',
        ], $overrides));
        DocumentRemoteState::reconcile();
        return $doc->fresh();
    }

    private function state(Document $doc): DocumentRemoteState
    {
        return DocumentRemoteState::query()->findOrFail($doc->id);
    }

    public function test_first_check_public_records_state_without_a_queue_row(): void
    {
        $doc = $this->doc();

        $change = $this->checker()->apply($doc, RemoteCheck::fromRestriction('Avalik'), $this->now);

        $this->assertNull($change);
        $state = $this->state($doc);
        $this->assertSame('public', $state->remote_status);
        $this->assertSame('Avalik', $state->remote_restriction);
        $this->assertNull($state->remote_restriction_basis);
        $this->assertFalse($state->personal_data_restriction);
        $this->assertSame('2026-09-07 12:00:00', $state->checked_at->toDateTimeString());
        $this->assertSame('2026-10-22 12:00:00', $state->next_check_at->toDateTimeString());
        $this->assertSame(0, $state->check_error_count);
        $this->assertSame(200, $state->last_http_status);
        $this->assertSame(0, DocumentStatusChange::count());
        $this->assertTrue($doc->fresh()->visible, 'the daemon never hides anything');
    }

    public function test_first_check_restricted_queues_a_change_from_ingest_time_public(): void
    {
        $doc = $this->doc();

        $change = $this->checker()->apply($doc, RemoteCheck::fromRestriction('AK', ['AvTS § 35 lg 1 p 12']), $this->now);

        $this->assertNotNull($change);
        $this->assertNull($change->from_status);
        $this->assertSame('restricted', $change->to_status);
        $this->assertNull($change->from_restriction);
        $this->assertSame('AK', $change->to_restriction);
        $this->assertSame('AvTS § 35 lg 1 p 12', $change->basis);
        $this->assertTrue($change->personal_data);
        $this->assertSame(200, $change->http_status);
        $this->assertNull($change->acknowledged_at);

        $state = $this->state($doc);
        $this->assertSame('restricted', $state->remote_status);
        $this->assertTrue($state->personal_data_restriction);
        $this->assertSame('2026-12-06 12:00:00', $state->next_check_at->toDateTimeString());
        $this->assertTrue($doc->fresh()->visible);
    }

    public function test_public_to_gone_queues_a_change(): void
    {
        $doc = $this->doc();
        $this->checker()->apply($doc, RemoteCheck::fromRestriction('Avalik'), $this->now);

        $change = $this->checker()->apply($doc, RemoteCheck::gone(404), $this->now->copy()->addDay());

        $this->assertNotNull($change);
        $this->assertSame('public', $change->from_status);
        $this->assertSame('gone', $change->to_status);
        $this->assertSame(404, $change->http_status);
        $this->assertFalse($change->personal_data);
        $this->assertSame('gone', $this->state($doc)->remote_status);
        $this->assertSame(1, DocumentStatusChange::count());
    }

    public function test_repeated_same_result_does_not_queue(): void
    {
        $doc = $this->doc();
        $restricted = RemoteCheck::fromRestriction('AK', ['AvTS § 35 lg 1 p 12']);
        $this->checker()->apply($doc, $restricted, $this->now);
        $this->checker()->apply($doc, $restricted, $this->now->copy()->addDays(90));
        $this->checker()->apply($doc, $restricted, $this->now->copy()->addDays(180));

        $this->assertSame(1, DocumentStatusChange::count());
    }

    public function test_restricted_to_public_queues_a_change(): void
    {
        $doc = $this->doc();
        $this->checker()->apply($doc, RemoteCheck::fromRestriction('AK', ['AvTS § 35 lg 1 p 17']), $this->now);

        $change = $this->checker()->apply($doc, RemoteCheck::fromRestriction('Avalik'), $this->now->copy()->addDay());

        $this->assertNotNull($change);
        $this->assertSame('restricted', $change->from_status);
        $this->assertSame('public', $change->to_status);
        $this->assertSame('AK', $change->from_restriction);
        $this->assertSame('Avalik', $change->to_restriction);
        $this->assertNull($change->basis);
    }

    public function test_basis_change_while_restricted_queues_a_change(): void
    {
        $doc = $this->doc();
        $this->checker()->apply($doc, RemoteCheck::fromRestriction('AK', ['AvTS § 35 lg 1 p 17']), $this->now);

        $change = $this->checker()->apply($doc, RemoteCheck::fromRestriction('AK', ['AvTS § 35 lg 1 p 12']), $this->now->copy()->addDay());

        $this->assertNotNull($change);
        $this->assertSame('restricted', $change->from_status);
        $this->assertSame('restricted', $change->to_status);
        $this->assertTrue($change->personal_data);
        $this->assertSame(2, DocumentStatusChange::count());
    }

    public function test_transient_error_changes_nothing_except_backoff(): void
    {
        $doc = $this->doc();
        $this->checker()->apply($doc, RemoteCheck::fromRestriction('Avalik'), $this->now);
        $before = $this->state($doc);

        $later = $this->now->copy()->addDays(45);
        $this->checker()->apply($doc, RemoteCheck::error(RemoteCheck::ERROR_HTTP_5XX, 503), $later);

        $state = $this->state($doc);
        $this->assertSame('public', $state->remote_status);
        $this->assertSame($before->checked_at->toDateTimeString(), $state->checked_at->toDateTimeString(), 'checked_at marks the last success');
        $this->assertSame(1, $state->check_error_count);
        $this->assertSame(503, $state->last_http_status);
        $this->assertSame($later->copy()->addHour()->toDateTimeString(), $state->next_check_at->toDateTimeString());
        $this->assertSame(0, DocumentStatusChange::count());

        $this->checker()->apply($doc, RemoteCheck::error(RemoteCheck::ERROR_TIMEOUT), $later);
        $state = $this->state($doc);
        $this->assertSame(2, $state->check_error_count);
        $this->assertSame($later->copy()->addHours(6)->toDateTimeString(), $state->next_check_at->toDateTimeString());
        $this->assertNull($state->last_http_status);
    }

    public function test_success_after_errors_resets_the_error_count(): void
    {
        $doc = $this->doc();
        $this->checker()->apply($doc, RemoteCheck::error(RemoteCheck::ERROR_HTTP_5XX, 500), $this->now);
        $this->checker()->apply($doc, RemoteCheck::error(RemoteCheck::ERROR_HTTP_5XX, 500), $this->now);

        $this->checker()->apply($doc, RemoteCheck::fromRestriction('Avalik'), $this->now);

        $this->assertSame(0, $this->state($doc)->check_error_count);
        $this->assertSame(0, DocumentStatusChange::count(), 'errors before the first success are not a transition');
    }

    public function test_check_probes_the_registry_and_persists(): void
    {
        $doc = $this->doc();
        Http::fake([$doc->url => Http::response(file_get_contents(__DIR__ . '/../__fixtures/adr_document_ak_18976496.html'), 200)]);

        $check = $this->checker()->check($doc);

        $this->assertSame(RemoteCheck::RESTRICTED, $check->outcome);
        $this->assertSame('restricted', $this->state($doc)->remote_status);
        $this->assertSame(1, DocumentStatusChange::count());
    }

    public function test_dry_run_probes_but_writes_nothing(): void
    {
        $doc = $this->doc();
        Http::fake([$doc->url => Http::response(file_get_contents(__DIR__ . '/../__fixtures/adr_document_ak_18976496.html'), 200)]);

        $check = $this->checker()->check($doc, dryRun: true);

        $this->assertSame(RemoteCheck::RESTRICTED, $check->outcome);
        $this->assertNull($this->state($doc)->remote_status);
        $this->assertNull($this->state($doc)->checked_at);
        $this->assertSame(0, DocumentStatusChange::count());
    }

    public function test_reconcile_only_creates_rows_for_public_at_ingest_documents(): void
    {
        $public = $this->doc();
        Document::create([
            'organisation_id' => $public->organisation_id,
            'url' => 'https://adr.rik.ee/som/dokument/2',
            'original_id' => '2', 'title' => 'AK doc', 'reference' => 'R', 'registration_date' => '2024-01-01', 'type' => 'Kiri',
            'restriction' => 'AK',
        ]);

        $created = DocumentRemoteState::reconcile();

        $this->assertSame(0, $created, 'the public document already had a row');
        $this->assertSame(1, DocumentRemoteState::count());
        $this->assertTrue(DocumentRemoteState::where('document_id', $public->id)->exists());
    }
}
