<?php

namespace Tests\Feature;

use App\Lib\Compliance\ReviewQueue;
use App\Models\ComplianceAction;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\Organisation;
use App\Models\ReviewItem;
use App\Models\TakedownRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private Document $doc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2');
        Storage::fake('r2_private');
        $org = Organisation::create(['name' => 'TEHIK', 'slug' => 'tehik', 'registry_base_uri' => 'https://adr.rik.ee/tehik/', 'fetcher_type' => 'delta-adr']);
        $this->doc = Document::create([
            'organisation_id' => $org->id, 'url' => 'https://adr.rik.ee/tehik/dokument/1', 'original_id' => '1',
            'title' => 'Pöördumine', 'reference' => '1-7/399-1', 'registration_date' => '2026-04-02', 'type' => 'Sissetulev kiri', 'restriction' => 'Avalik',
        ]);
        Storage::disk('r2')->put('K/kiri.pdf', 'pdf');
        File::create(['document_id' => $this->doc->id, 'name' => 'kiri.pdf', 'location' => 'K/kiri.pdf', 'contents' => 'Pöördumise esitaja Mari Maasikas.']);
        DocumentRemoteState::reconcile();
    }

    private function admin(): static
    {
        return $this->withSession(['is_admin' => true])->from(route('document', ['document' => $this->doc->id]));
    }

    public function test_admins_see_every_admin_section_on_the_document_page(): void
    {
        DocumentStatusChange::create(['document_id' => $this->doc->id, 'occurred_at' => now(), 'to_status' => 'restricted', 'to_restriction' => 'AK', 'basis' => 'AvTS § 35 lg 1 p 12', 'personal_data' => true, 'http_status' => 200]);
        $this->doc->takedownRequests()->create([
            'status' => TakedownRequest::STATUS_PENDING, 'author_name' => 'Mari Maasikas', 'author_email' => 'mari@example.com',
            'legal_basis' => TakedownRequest::LEGAL_BASES[0], 'objection_note' => 'Palun eemaldage minu nimi.', 'token' => str_repeat('a', 40), 'verified_at' => now(),
        ]);
        app(ReviewQueue::class)->flag($this->doc, null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'AKI 123', null, ['public_sector' => true, 'personal_data' => true]);

        $this->admin()->get(route('document', $this->doc))->assertOk()
            ->assertSee('Otsus')->assertSee('ootab otsust')->assertSee('AKI 123')
            ->assertSee('Eemaldamistaotlused (1)')->assertSee('Palun eemaldage minu nimi.')->assertSee('Rahulda ja redigeeri isikuandmed')
            ->assertSee('Allikas')->assertSee('AvTS § 35 lg 1 p 12')
            ->assertSee('Isikuandmed')->assertSee('Kontrolli isikuandmeid')
            ->assertSee('Logi');

        $this->flushSession();
        $this->get(route('document', $this->doc))->assertOk()
            ->assertDontSee('ootab otsust')->assertDontSee('Rahulda ja redigeeri isikuandmed')->assertDontSee('mari@example.com');
    }

    public function test_decide_remove_from_the_panel(): void
    {
        $this->admin()->post(route('document.decide', $this->doc), ['decision' => 'remove', 'reason' => 'Allikas piiras; AKI ettekirjutus.', 'reference' => 'AKI 123'])
            ->assertRedirect(route('document', ['document' => $this->doc->id]))->assertSessionHas('success');

        $this->assertTrue($this->doc->fresh()->isRemoved());
        Storage::disk('r2')->assertMissing('K/kiri.pdf');
        $item = ReviewItem::where('document_id', $this->doc->id)->sole();
        $this->assertSame(['remove', 'haldus', 'AKI 123'], [$item->decision, $item->decided_by, $item->decision_reference]);

        // The page still renders for the admin, without a decision form.
        $this->admin()->get(route('document', $this->doc))->assertOk()->assertSee('eemaldatud')->assertDontSee('decision-form', false);
    }

    public function test_decide_redact_with_strings_from_the_panel(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->admin()->post(route('document.decide', $this->doc), ['decision' => 'redact', 'reason' => 'Eraisiku nimi.', 'strings' => "Mari Maasikas\r\n\r\n"])
            ->assertSessionHas('success');

        $this->assertSame('redact', ReviewItem::sole()->decision);
        $this->assertSame(['Mari Maasikas'], array_column(\App\Models\PiiRedaction::sole()->plan['replacements'], 'surface'));
    }

    public function test_a_redaction_that_fails_reopens_its_item(): void
    {
        // "Mari" is replaced as a word, but "Marimaa" still contains it: the
        // redactor's leftover check fails and rolls the text step back.
        File::create(['document_id' => $this->doc->id, 'name' => 'lisa.txt', 'location' => 'K/lisa.txt', 'contents' => 'Mari elab Marimaa talus.']);

        $this->admin()->post(route('document.decide', $this->doc), ['decision' => 'redact', 'reason' => 'Eraisiku nimi.', 'strings' => 'Mari'])
            ->assertSessionHas('success');

        $redaction = \App\Models\PiiRedaction::sole();
        $this->assertSame('failed', $redaction->text_status);
        $this->assertStringContainsString('Mari elab', File::where('name', 'lisa.txt')->value('contents'), 'nothing changed');

        $item = ReviewItem::where('document_id', $this->doc->id)->sole();
        $this->assertTrue($item->isOpen(), 'the decision did not take effect, so it is open again');
        $this->assertNull($item->decision);
        $failed = ComplianceAction::where('review_item_id', $item->id)->where('effect', 'redaction_failed')->sole();
        $this->assertSame(['Mari'], $failed->result['leftover']);
        $this->assertTrue(ComplianceAction::where('review_item_id', $item->id)->where('effect', 'reopened')->exists());
    }

    public function test_a_failed_redaction_leaves_its_assessment_unreviewed(): void
    {
        $extraction = \App\Models\PiiExtraction::create(['document_id' => $this->doc->id, 'model' => 't', 'status' => 'done', 'prompt_version' => 1, 'schema_version' => 1]);
        $assessment = \App\Models\PiiAssessment::create(['document_id' => $this->doc->id, 'extraction_id' => $extraction->id, 'rules_version' => 1, 'band' => 'HIGH', 'recommendation' => 'REDACT', 'computed_at' => now()]);
        $assessment->review(\App\Models\PiiAssessment::REVIEW_REDACTED, 'haldus');
        $item = app(\App\Lib\Compliance\ReviewQueue::class)->flag($this->doc, null, ReviewItem::SOURCE_PII, ReviewItem::PRIORITY_PII, null, null);
        $item->forceFill(['status' => 'decided', 'decision' => 'redact', 'decided_at' => now()])->save();
        $redaction = \App\Models\PiiRedaction::create(['document_id' => $this->doc->id, 'assessment_id' => $assessment->id, 'applied_by' => 'haldus', 'plan' => [],
            'text_status' => 'failed', 'files_status' => 'skipped', 'log' => [['step' => 'text', 'outcome' => 'failed', 'leftover' => [['surface' => 'X', 'in' => []]]]]]);

        app(\App\Lib\Compliance\ReviewQueue::class)->reopenAfterFailedRedaction($redaction);

        $this->assertTrue($item->fresh()->isOpen());
        $this->assertNull($assessment->fresh()->reviewed_at, 'nothing was redacted, so the assessment needs review again');
    }

    public function test_keep_can_be_overridden_by_a_later_decision(): void
    {
        $this->admin()->post(route('document.decide', $this->doc), ['decision' => 'keep', 'reason' => 'Ametnik tööülesannetes.'])->assertSessionHas('success');
        $this->admin()->post(route('document.decide', $this->doc), ['decision' => 'remove', 'reason' => 'Uus asjaolu: AKI kiri.'])->assertSessionHas('success');

        $this->assertTrue($this->doc->fresh()->isRemoved());
        $effects = ComplianceAction::where('document_id', $this->doc->id)->orderBy('id')->pluck('effect')->all();
        $this->assertContains('decided_keep', $effects);
        $this->assertContains('reopened', $effects);
        $this->assertContains('decided_remove', $effects);
    }

    public function test_decide_needs_a_reason_and_reports_errors(): void
    {
        $this->admin()->post(route('document.decide', $this->doc), ['decision' => 'remove'])->assertSessionHasErrors('reason');
        $this->admin()->post(route('document.decide', $this->doc), ['decision' => 'redact', 'reason' => 'x'])
            ->assertSessionHas('error'); // no assessment and no strings
        $this->flushSession();
        $this->post(route('document.decide', $this->doc), ['decision' => 'keep', 'reason' => 'x'])->assertForbidden();
    }

    public function test_old_detail_pages_lead_to_the_panel_and_buttons_are_logged(): void
    {
        $t = $this->doc->takedownRequests()->create([
            'status' => TakedownRequest::STATUS_PENDING, 'author_name' => 'A', 'author_email' => 'a@example.com',
            'legal_basis' => TakedownRequest::LEGAL_BASES[1], 'token' => str_repeat('b', 40),
        ]);
        $this->admin()->get(route('takedowns.show', $t))->assertRedirect(route('document', ['document' => $this->doc->id]) . '#taotlus-' . $t->id);

        $this->admin()->post(route('pii.hide', $this->doc))->assertRedirect();
        $this->admin()->post(route('pii.unhide', $this->doc))->assertRedirect();
        $this->assertSame(['hidden', 'unhidden'], ComplianceAction::where('document_id', $this->doc->id)->orderBy('id')->pluck('effect')->all());
    }
}
