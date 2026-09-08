<?php

namespace Tests\Feature;

use App\Lib\Recheck\CheckPolicy;
use App\Lib\Recheck\RemoteCheck;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\TakedownRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckPolicyTest extends TestCase
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

    private function policy(): CheckPolicy
    {
        return new CheckPolicy([
            'intervals' => [
                'takedown' => 7,
                'recent' => 14,
                'recent_age_days' => 180,
                'old' => 45,
                'changed' => 90,
            ],
            'error_backoff_hours' => [1, 6, 24, 72],
        ]);
    }

    private function doc(string $registered): Document
    {
        $org = Organisation::create([
            'name' => 'Org', 'slug' => 'org-' . uniqid(), 'registry_base_uri' => 'https://x.example/' . uniqid(), 'fetcher_type' => 'delta-adr',
        ]);
        return Document::create([
            'organisation_id' => $org->id,
            'url' => 'https://x.example/dokument/' . uniqid(),
            'original_id' => '1',
            'title' => 'T',
            'reference' => 'R',
            'registration_date' => $registered,
            'type' => 'Kiri',
            'restriction' => 'Avalik',
        ]);
    }

    private function public(): RemoteCheck
    {
        return RemoteCheck::fromRestriction('Avalik');
    }

    public function test_recent_public_document_is_rechecked_in_14_days(): void
    {
        $next = $this->policy()->nextCheckAfterSuccess($this->doc('2026-08-01'), $this->public(), $this->now);
        $this->assertSame('2026-09-21 12:00:00', $next->toDateTimeString());
    }

    public function test_old_public_document_is_rechecked_in_45_days(): void
    {
        $next = $this->policy()->nextCheckAfterSuccess($this->doc('2024-01-01'), $this->public(), $this->now);
        $this->assertSame('2026-10-22 12:00:00', $next->toDateTimeString());
    }

    public function test_document_with_pending_takedown_is_rechecked_in_7_days(): void
    {
        $doc = $this->doc('2024-01-01');
        $doc->takedownRequests()->create([
            'status' => TakedownRequest::STATUS_PENDING,
            'author_name' => 'A', 'author_email' => 'a@example.com', 'legal_basis' => 'x',
        ]);

        $next = $this->policy()->nextCheckAfterSuccess($doc, $this->public(), $this->now);
        $this->assertSame('2026-09-14 12:00:00', $next->toDateTimeString());
    }

    public function test_denied_takedown_does_not_shorten_the_interval(): void
    {
        $doc = $this->doc('2024-01-01');
        $doc->takedownRequests()->create([
            'status' => TakedownRequest::STATUS_DENIED,
            'author_name' => 'A', 'author_email' => 'a@example.com', 'legal_basis' => 'x',
        ]);

        $next = $this->policy()->nextCheckAfterSuccess($doc, $this->public(), $this->now);
        $this->assertSame('2026-10-22 12:00:00', $next->toDateTimeString());
    }

    public function test_restricted_or_gone_document_is_rechecked_in_90_days(): void
    {
        $doc = $this->doc('2026-08-01');
        $restricted = RemoteCheck::fromRestriction('AK', ['AvTS § 35 lg 1 p 12']);
        $gone = RemoteCheck::gone(404);

        $this->assertSame('2026-12-06 12:00:00', $this->policy()->nextCheckAfterSuccess($doc, $restricted, $this->now)->toDateTimeString());
        $this->assertSame('2026-12-06 12:00:00', $this->policy()->nextCheckAfterSuccess($doc, $gone, $this->now)->toDateTimeString());
    }

    public function test_error_backoff_grows_and_caps(): void
    {
        $p = $this->policy();
        $this->assertSame('2026-09-07 13:00:00', $p->nextCheckAfterError(1, $this->now)->toDateTimeString());
        $this->assertSame('2026-09-07 18:00:00', $p->nextCheckAfterError(2, $this->now)->toDateTimeString());
        $this->assertSame('2026-09-08 12:00:00', $p->nextCheckAfterError(3, $this->now)->toDateTimeString());
        $this->assertSame('2026-09-10 12:00:00', $p->nextCheckAfterError(4, $this->now)->toDateTimeString());
        $this->assertSame('2026-09-10 12:00:00', $p->nextCheckAfterError(9, $this->now)->toDateTimeString());
    }
}
