<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentPii;
use App\Jobs\RedactDocument;
use App\Jobs\RevertRedaction;
use App\Lib\Pii\Contexts;
use App\Lib\Pii\Extractor;
use App\Lib\Pii\Rules;
use App\Lib\Pii\Schema;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use App\Models\PiiAssessment;
use App\Models\PiiExtraction;
use App\Models\PiiRedaction;
use App\Models\PiiSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiiAdminTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2');
        Storage::fake('r2_private');
        $this->org = Organisation::create(['name' => 'SOM', 'slug' => 'som', 'registry_base_uri' => 'https://adr.rik.ee/som/', 'fetcher_type' => 'delta-adr']);
    }

    private function asAdmin(): static
    {
        return $this->withSession(['is_admin' => true]);
    }

    private function doc(): Document
    {
        $doc = Document::create([
            'organisation_id' => $this->org->id, 'url' => 'https://adr.rik.ee/som/dokument/1', 'original_id' => '1',
            'title' => 'Kiri Mari Maasikale', 'reference' => 'R', 'registration_date' => '2024-01-01', 'type' => 'Kiri', 'restriction' => 'Avalik',
        ]);
        File::create(['document_id' => $doc->id, 'name' => 'kiri.pdf', 'location' => 'x/kiri.pdf', 'contents' => 'Tere Mari Maasikas, siin on pikem tekst mis ületab viiskümmend märki kindlasti.', 'parsed_with' => \App\Lib\Parser\PdfParser::class]);
        return $doc;
    }

    /** A done extraction with one private subject and its assessment. */
    private function assessed(Document $doc): PiiAssessment
    {
        $e = PiiExtraction::create([
            'document_id' => $doc->id, 'model' => 'fake', 'prompt_version' => Extractor::PROMPT_VERSION, 'schema_version' => Schema::VERSION,
            'status' => PiiExtraction::STATUS_DONE, 'flags' => [], 'matter' => 'OTHER', 'summary' => 's', 'raw_response' => [],
        ]);
        PiiSubject::create([
            'extraction_id' => $e->id, 'document_id' => $doc->id, 'name' => 'Mari Maasikas', 'context' => Contexts::PRIVATE_PERSON_APPLICANT,
            'surface_forms' => [['text' => 'Mari Maasikas', 'in' => ['body:1']], ['text' => 'Mari Maasikale', 'in' => ['title']]], 'identifiers' => [], 'subject_flags' => [],
        ]);
        return (new \App\Lib\Pii\Assessor())->assess($doc);
    }

    public function test_pages_require_admin(): void
    {
        $doc = $this->doc();
        $this->get(route('pii.index'))->assertForbidden();
        $this->get(route('pii.show', $doc))->assertForbidden();
        $this->post(route('pii.extract', $doc))->assertForbidden();
        $this->post(route('pii.hide', $doc))->assertForbidden();
    }

    public function test_index_and_show_render(): void
    {
        $doc = $this->doc();
        $a = $this->assessed($doc);
        $this->assertSame(PiiAssessment::BAND_WARN, $a->band);

        $this->asAdmin()->get(route('pii.index'))->assertOk()->assertSee('Kiri Mari Maasikale')->assertSee('Hoiatus');
        $this->asAdmin()->get(route('pii.index', ['group' => 1]))->assertOk()->assertSee('SOM');
        $this->asAdmin()->get(route('pii.index', ['band' => 'HIGH']))->assertOk()->assertDontSee('Kiri Mari Maasikale');
        $this->asAdmin()->get(route('pii.show', $doc))->assertOk()
            ->assertSee('Mari Maasikas')
            ->assertSee('Rakenda redigeerimine')
            ->assertSee('M. M.');
    }

    public function test_extract_button_queues_admin_request(): void
    {
        Queue::fake();
        $doc = $this->doc();
        $this->asAdmin()->post(route('pii.extract', $doc))->assertRedirect();
        Queue::assertPushed(ExtractDocumentPii::class);
        $row = PiiExtraction::first();
        $this->assertSame(PiiExtraction::REQUESTED_BY_ADMIN, $row->requested_by);
        $this->assertNotNull($row->queued_at);
        $this->assertTrue($doc->fresh()->visible);
    }

    public function test_override_reassesses_immediately(): void
    {
        $doc = $this->doc();
        $a = $this->assessed($doc);
        $s = PiiSubject::first();
        $this->asAdmin()->post(route('pii.override', $s), ['context_override' => Contexts::REPRESENTING_LEGAL_ENTITY])->assertRedirect();
        $latest = PiiAssessment::latest('id')->first();
        $this->assertNotSame($a->id, $latest->id);
        $this->assertSame(PiiAssessment::BAND_INFO, $latest->band);
        $this->assertSame(Rules::VERSION, $latest->rules_version);
    }

    public function test_redact_creates_plan_and_dispatches_only_once(): void
    {
        Queue::fake();
        $doc = $this->doc();
        $a = $this->assessed($doc);
        $this->asAdmin()->post(route('pii.redact', $doc))->assertRedirect()->assertSessionHas('success');
        Queue::assertPushed(RedactDocument::class, 1);
        $r = PiiRedaction::first();
        $this->assertSame('M. M.', collect($r->plan['replacements'])->firstWhere('surface', 'Mari Maasikas')['replacement']);
        $this->assertSame(PiiAssessment::REVIEW_REDACTED, $a->fresh()->review_action);
        $this->assertNull($doc->fresh()->redacted_at, 'nothing changes until the job runs');

        $this->asAdmin()->post(route('pii.redact', $doc))->assertRedirect()->assertSessionHas('error');
        Queue::assertPushed(RedactDocument::class, 1);

        $r->forceFill(['text_status' => PiiRedaction::STATUS_APPLIED, 'files_status' => PiiRedaction::STATUS_APPLIED])->save();
        $this->asAdmin()->get(route('pii.show', $doc))->assertOk()->assertSee('Taasta');
        $this->asAdmin()->post(route('pii.revert', $r))->assertRedirect();
        Queue::assertPushed(RevertRedaction::class, 1);
    }

    public function test_retry_files_requeues_only_a_failed_file_step(): void
    {
        Queue::fake();
        $doc = $this->doc();
        $this->assessed($doc);
        $r = PiiRedaction::create(['document_id' => $doc->id, 'applied_by' => 'admin', 'plan' => ['replacements' => [], 'files' => []],
            'text_status' => PiiRedaction::STATUS_APPLIED, 'files_status' => PiiRedaction::STATUS_FAILED]);
        $this->asAdmin()->get(route('pii.show', $doc))->assertOk()->assertSee('Proovi faile uuesti');
        $this->asAdmin()->post(route('pii.retryFiles', $r))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(PiiRedaction::STATUS_PENDING, $r->fresh()->files_status);
        Queue::assertPushed(RedactDocument::class, 1);

        $r->forceFill(['text_status' => PiiRedaction::STATUS_FAILED])->save();
        $this->asAdmin()->post(route('pii.retryFiles', $r))->assertRedirect()->assertSessionHas('error');
    }

    public function test_hide_and_unhide_are_explicit_admin_actions(): void
    {
        $doc = $this->doc();
        $a = $this->assessed($doc);
        $this->asAdmin()->post(route('pii.hide', $doc))->assertRedirect();
        $this->assertFalse($doc->fresh()->visible);
        $this->assertSame(PiiAssessment::REVIEW_HIDDEN, $a->fresh()->review_action);
        $this->asAdmin()->post(route('pii.unhide', $doc))->assertRedirect();
        $this->assertTrue($doc->fresh()->visible);
    }

    public function test_document_page_shows_admin_warning_and_withheld_file_notice(): void
    {
        $doc = $this->doc();
        $this->assessed($doc);
        $this->asAdmin()->get(route('document', ['document' => $doc->id]))->assertOk()->assertSee('Isikuandmed (admin)')->assertSee('Hoiatus');
        $this->flushSession();
        $this->withSession(['is_admin' => false])->get(route('document', ['document' => $doc->id]))->assertOk()->assertDontSee('Isikuandmed (admin)');

        $doc->files->first()->update(['original_withheld' => true]);
        $doc->update(['redacted_at' => now()]);
        $this->get(route('document', ['document' => $doc->id]))->assertOk()
            ->assertSee('Originaalfail on isikuandmete kaitseks eemaldatud')
            ->assertSee('eemaldatud eraisikute andmed');
    }
}
