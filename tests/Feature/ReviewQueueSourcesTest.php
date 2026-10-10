<?php

namespace Tests\Feature;

use App\Jobs\AssessDocumentPii;
use App\Lib\Compliance\ReviewQueue;
use App\Lib\Pii\Assessor;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\Organisation;
use App\Models\PiiAssessment;
use App\Models\PiiExtraction;
use App\Models\ReviewItem;
use App\Models\TakedownRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReviewQueueSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::create(['name' => 'RMK', 'slug' => 'rmk', 'registry_base_uri' => 'https://adr.rmk.ee/', 'fetcher_type' => 'rmk']);
    }

    private function doc(array $overrides = []): Document
    {
        return Document::create(array_merge([
            'organisation_id' => $this->org->id, 'url' => 'https://adr.rmk.ee/dokument/' . random_int(1, 999999), 'original_id' => '1',
            'title' => 'Käskkiri', 'reference' => 'R', 'registration_date' => '2014-01-01', 'type' => 'Käskkiri', 'restriction' => 'Avalik',
        ], $overrides));
    }

    private function change(Document $doc, string $to, bool $pd = false, array $overrides = []): DocumentStatusChange
    {
        return DocumentStatusChange::create(array_merge(['document_id' => $doc->id, 'occurred_at' => now(), 'to_status' => $to, 'personal_data' => $pd, 'http_status' => 200], $overrides));
    }

    private function takedown(Document $doc, array $overrides = []): TakedownRequest
    {
        return $doc->takedownRequests()->create(array_merge([
            'status' => TakedownRequest::STATUS_PENDING, 'author_name' => 'Mari', 'author_email' => 'mari@example.com',
            'legal_basis' => TakedownRequest::LEGAL_BASES[0], 'token' => bin2hex(random_bytes(20)), 'verified_at' => now(),
        ], $overrides));
    }

    private function highAssessment(Document $doc): PiiAssessment
    {
        $ex = PiiExtraction::create(['document_id' => $doc->id, 'model' => 't', 'status' => 'done', 'prompt_version' => \App\Lib\Pii\Extractor::PROMPT_VERSION, 'schema_version' => 1]);
        return PiiAssessment::create(['document_id' => $doc->id, 'extraction_id' => $ex->id, 'rules_version' => 1, 'band' => PiiAssessment::BAND_HIGH, 'recommendation' => PiiAssessment::RECOMMEND_REVIEW_WITHHOLD, 'computed_at' => now()]);
    }

    public function test_a_verified_takedown_is_queued_and_its_pii_check_started(): void
    {
        Mail::fake();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ExtractDocumentPii::class]);
        $doc = $this->doc();
        $t = $this->takedown($doc, ['status' => TakedownRequest::STATUS_UNVERIFIED, 'verified_at' => null, 'author_email' => 'x@rmk.ee', 'verification_code' => '123456', 'verification_code_expires_at' => now()->addMinutes(30)]);

        $this->post(route('takedowns.verify', $t), ['verification_code' => '123456'])->assertRedirect();

        $item = ReviewItem::where('document_id', $doc->id)->sole();
        $this->assertSame('takedown', $item->flags[0]['source']);
        $this->assertSame("eemaldamistaotlus #{$t->id}", $item->flags[0]['reference']);
        $this->assertTrue($item->flags[0]['public_sector'], 'rmk.ee is a public-sector sender');
        $this->assertTrue($item->flags[0]['personal_data'], 'GDPR art 21 objection');

        $extraction = PiiExtraction::where('document_id', $doc->id)->sole();
        $this->assertSame([PiiExtraction::REQUESTED_BY_TAKEDOWN, PiiExtraction::STATUS_PENDING], [$extraction->requested_by, $extraction->status]);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ExtractDocumentPii::class, fn($job) => $job->extractionId === $extraction->id);
    }

    public function test_takedown_pii_check_command_skips_documents_already_checked(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ExtractDocumentPii::class]);
        $unchecked = $this->doc();
        $this->takedown($unchecked);
        $checked = $this->doc();
        $this->takedown($checked);
        $this->highAssessment($checked);              // done extraction exists
        $this->takedown($this->doc(), ['status' => TakedownRequest::STATUS_DENIED]);

        $this->artisan('pii:extract-takedowns', ['--dry-run' => true])->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertNothingPushed();

        $this->artisan('pii:extract-takedowns')->assertSuccessful();
        $this->assertSame([$unchecked->id], PiiExtraction::where('requested_by', 'takedown')->pluck('document_id')->all());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ExtractDocumentPii::class, 1);

        $this->artisan('pii:extract-takedowns')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ExtractDocumentPii::class, 1); // already queued: not again
    }

    public function test_a_high_assessment_is_queued_by_the_assess_job(): void
    {
        $doc = $this->doc();
        $assessment = $this->highAssessment($doc);
        $this->app->bind(Assessor::class, fn() => new class($assessment) extends Assessor {
            public function __construct(private PiiAssessment $a) {}
            public function assess(Document $document): ?PiiAssessment { return $this->a; }
        });

        (new AssessDocumentPii($doc->id))->handle(app(Assessor::class));

        $item = ReviewItem::where('document_id', $doc->id)->sole();
        $this->assertSame(['pii', ReviewItem::PRIORITY_PII], [$item->flags[0]['source'], $item->priority]);
    }

    public function test_status_changes_back_to_public_are_not_queued(): void
    {
        $this->assertNull(app(ReviewQueue::class)->flagStatusChange($this->change($this->doc(), 'public')));
        $this->assertSame(0, ReviewItem::count());
    }

    public function test_backfill_queues_the_existing_backlog_once(): void
    {
        $restricted = $this->doc();
        $this->change($restricted, 'restricted', true);
        $gone = $this->doc();
        $this->change($gone, 'gone');
        $this->change($this->doc(), 'gone', false, ['acknowledged_at' => now()]);  // already handled
        $this->change($this->doc(), 'public');                                       // not a review matter
        $withTakedown = $this->doc();
        $this->takedown($withTakedown);
        $this->takedown($this->doc(), ['status' => TakedownRequest::STATUS_DENIED]);
        $high = $this->doc();
        $this->highAssessment($high);
        $hidden = $this->doc(['visible' => false]);
        File::create(['document_id' => $hidden->id, 'name' => 'a.pdf', 'location' => 'K/a.pdf']);
        $hiddenWithheld = $this->doc(['visible' => false]);
        File::create(['document_id' => $hiddenWithheld->id, 'name' => 'b.pdf', 'location' => 'K/b.pdf', 'original_withheld' => true]);
        // Also flagged by the daemon: joins the same item.
        $this->takedown($restricted);

        $this->artisan('compliance:backfill-queue', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, ReviewItem::count(), 'dry run writes nothing');

        $this->artisan('compliance:backfill-queue')->assertSuccessful();

        $queued = ReviewItem::orderBy('priority')->orderBy('document_id')->get();
        $this->assertEqualsCanonicalizing([$restricted->id, $gone->id, $withTakedown->id, $high->id, $hidden->id], $queued->pluck('document_id')->all());
        $this->assertCount(2, ReviewItem::where('document_id', $restricted->id)->sole()->flags);
        $this->assertSame(ReviewItem::PRIORITY_GONE, ReviewItem::where('document_id', $gone->id)->sole()->priority);
        $this->assertSame('peidetud', ReviewItem::where('document_id', $hidden->id)->sole()->flags[0]['source']);
        $this->assertTrue($restricted->fresh()->visible, 'flagging never changes visibility');

        $this->artisan('compliance:backfill-queue')->assertSuccessful();
        $this->assertSame(5, ReviewItem::count(), 'a re-run adds nothing');
        $this->assertCount(2, ReviewItem::where('document_id', $restricted->id)->sole()->flags);
    }
}
