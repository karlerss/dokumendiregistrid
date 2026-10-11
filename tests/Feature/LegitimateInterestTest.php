<?php

namespace Tests\Feature;

use App\Lib\Pii\Assessor;
use App\Lib\Pii\Contexts;
use App\Lib\Pii\Extractor;
use App\Lib\Pii\Flags;
use App\Lib\Pii\LegitimateInterest;
use App\Lib\Pii\Schema;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\Organisation;
use App\Models\PiiExtraction;
use App\Models\PiiRedaction;
use App\Models\PiiSubject;
use App\Models\ReviewItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegitimateInterestTest extends TestCase
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

    /**
     * A document with one done extraction and the given people, assessed.
     *
     * @param array<int, array{0: string, 1: string, 2?: array, 3?: ?string}> $people [name, context, identifiers, personal code]
     */
    private function document(array $people, array $flags = []): Document
    {
        $doc = Document::create([
            'organisation_id' => $this->org->id, 'url' => 'https://adr.rik.ee/som/dokument/1', 'original_id' => '1',
            'title' => 'Kiri', 'reference' => 'R', 'registration_date' => '2024-01-01', 'type' => 'Kiri', 'restriction' => 'Avalik',
        ]);
        File::create(['document_id' => $doc->id, 'name' => 'kiri.pdf', 'location' => 'x/kiri.pdf', 'contents' => 'Pikk tekst, mis ületab viiskümmend märki kindlasti ja sisaldab nimesid.', 'parsed_with' => \App\Lib\Parser\PdfParser::class]);
        $e = PiiExtraction::create([
            'document_id' => $doc->id, 'model' => 'fake', 'prompt_version' => Extractor::PROMPT_VERSION, 'schema_version' => Schema::VERSION,
            'status' => PiiExtraction::STATUS_DONE, 'flags' => $flags, 'matter' => 'CONTRACT', 'summary' => 'Asutus sõlmis üürilepingu.',
            'public_interest' => 'HIGH', 'public_interest_reason' => 'Puudutab avaliku raha kasutamist.', 'raw_response' => [],
        ]);
        foreach ($people as [$name, $context, $identifiers, $code]) {
            PiiSubject::create([
                'extraction_id' => $e->id, 'document_id' => $doc->id, 'name' => $name, 'personal_code' => $code ?? null, 'context' => $context,
                'surface_forms' => [['text' => $name, 'in' => ['body:1']]], 'identifiers' => $identifiers ?? [], 'subject_flags' => [],
            ]);
        }
        (new Assessor())->assess($doc);
        return $doc->fresh();
    }

    private function restrictAtSource(Document $doc): void
    {
        DocumentRemoteState::create(['document_id' => $doc->id, 'remote_status' => DocumentRemoteState::STATUS_RESTRICTED,
            'remote_restriction' => 'AK', 'remote_restriction_basis' => 'AvTS § 35 lg 1 p 12', 'personal_data_restriction' => true, 'checked_at' => now()]);
        DocumentStatusChange::create(['document_id' => $doc->id, 'occurred_at' => '2026-09-14 08:30:00', 'from_status' => 'public', 'to_status' => 'restricted',
            'from_restriction' => 'Avalik', 'to_restriction' => 'AK', 'basis' => 'AvTS § 35 lg 1 p 12', 'personal_data' => true]);
    }

    private function redact(Document $doc): void
    {
        PiiRedaction::create(['document_id' => $doc->id, 'applied_by' => 'admin', 'plan' => ['replacements' => [['surface' => 'x']], 'files' => []],
            'text_status' => PiiRedaction::STATUS_APPLIED, 'files_status' => PiiRedaction::STATUS_APPLIED]);
    }

    private function lia(Document $doc): array
    {
        $lia = (new LegitimateInterest())->assess($doc->fresh());
        $this->assertSame(['outcome', 'outcome_label', 'outcome_reason', 'sections', 'basis'], array_keys($lia));
        $this->assertSame(3, $lia['basis']['lia_version']);
        $this->assertCount(4, $lia['sections']);
        foreach ($lia['sections'] as $section) {
            foreach ($section['paragraphs'] as $paragraph) {
                $this->assertDoesNotMatchRegularExpression('/^\s*(?:[•*–—-]|\d+[.)])\s/u', $paragraph);
            }
        }
        $this->assertSame([$lia['outcome_reason']], $lia['sections'][3]['paragraphs']);
        return $lia;
    }

    public function test_officials_only_justifies_keeping_even_after_a_source_restriction(): void
    {
        $doc = $this->document([
            ['Mari Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [], '48001010000'], // a personal code follows the name
            ['Jaan Esindaja', Contexts::REPRESENTING_LEGAL_ENTITY, [], null],
        ], [Flags::RESTRICTION_STAMP_PRESENT]);
        $this->restrictAtSource($doc);

        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::PASS, $lia['outcome']);
        $this->assertStringContainsString('dokumendi avalikuks jätmine õigustatud', $lia['outcome_reason']);
        $text = collect($lia['sections'])->pluck('paragraphs')->flatten()->implode("\n");
        $this->assertStringContainsString('Dokumendis esineb ametiülesannetes Mari Ametnik.', $text);
        $this->assertStringContainsString('14.09.2026 tuvastati, et allikas oli piiranud dokumendile juurdepääsu AvTS § 35 lg 1 p 12 alusel.', $text);
        $this->assertStringContainsString('märge seob avaliku sektori asutusi ega laiene iseenesest kolmandast isikust taasavaldajale', $text);
        $this->assertStringContainsString('Isikukoodi käsitatakse nimega samaväärse tuvastamistunnusena ja selle säilitamist põhjendab sama roll.', $text);
        $this->assertStringContainsString('AvTS § 35 lg 1 p 12', $lia['outcome_reason']);
        $this->assertStringContainsString('IKÜM art 6 lg 1 p f', $lia['outcome_reason']);
        $this->assertStringNotContainsString('48001010000', $text);
    }

    public function test_private_person_fails_until_redacted_then_passes_with_the_history(): void
    {
        $doc = $this->document([['Mari Maasikas', Contexts::PRIVATE_PERSON_APPLICANT, [], '48001010000']]);
        $this->restrictAtSource($doc);
        $this->assertSame(LegitimateInterest::FAIL, $this->lia($doc)['outcome']);

        $this->redact($doc);
        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::PASS_REDACTED, $lia['outcome']);
        $text = collect($lia['sections'])->pluck('paragraphs')->flatten()->implode("\n");
        $this->assertStringContainsString('Dokumendis esineb eraisikust pöördujana M. M.', $text);
        $this->assertStringContainsString('asendada nimed initsiaalidega ning eemaldada isikukoodid', $text);
        $this->assertStringContainsString('Teksti on redigeeritud', $text);
        $this->assertStringContainsString('IKÜM art 5 lg 1 p c, võimalikult väheste andmete kogumise põhimõte', $text);
        $this->assertStringContainsString('Rakendatud minimeerimine vähendab otsest seost eraasja ja isiku vahel', $text);
        $this->assertStringNotContainsString('M. M..', $text);
        $this->assertStringNotContainsString('Mari Maasikas', $text);
        $this->assertStringNotContainsString('48001010000', $text);
    }

    public function test_officials_private_contacts_need_review(): void
    {
        $doc = $this->document([['Mari Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [['type' => 'POSTAL_ADDRESS', 'value' => 'Kodu 1', 'nature' => 'PRIVATE', 'verified' => true]], null]]);
        $this->assertSame(LegitimateInterest::NEEDS_REVIEW, $this->lia($doc)['outcome']);
    }

    public function test_scanned_content_needs_review_even_when_redacted(): void
    {
        $doc = $this->document([['Mari Maasikas', Contexts::PRIVATE_PERSON_APPLICANT, [], null]], [Flags::SCANNED_OR_IMAGE_CONTENT]);
        $this->redact($doc);
        $this->assertSame(LegitimateInterest::NEEDS_REVIEW, $this->lia($doc)['outcome']);
    }

    public function test_free_text_does_not_repeat_private_names_or_any_personal_codes(): void
    {
        $doc = $this->document([
            ['Mari Maasikas', Contexts::PRIVATE_PERSON_APPLICANT, [], '48001010000'],
            ['Jaan Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [], '38001010000'],
        ]);
        $doc->latestPiiAssessment->extraction->update([
            'summary' => 'Asutus vastas Mari Maasikale ja Mari Maasikas esitas täienduse.',
            'public_interest_reason' => 'Jaan Ametnik (38001010000) vastas Mari Maasikale (48001010000).',
        ]);
        $doc->latestPiiAssessment->extraction->subjects()->where('name', 'Mari Maasikas')->first()->update([
            'surface_forms' => [['text' => 'Mari Maasikas'], ['text' => 'Mari Maasikale']],
        ]);
        ReviewItem::create([
            'document_id' => $doc->id, 'status' => ReviewItem::STATUS_DECIDED, 'priority' => ReviewItem::PRIORITY_PII,
            'flags' => [], 'first_flagged_at' => now(), 'decided_at' => '2026-09-15 12:00:00', 'decided_by' => 'haldus',
            'decision' => ReviewItem::DECISION_KEEP,
            'decision_reason' => 'Mari Maasikas (48001010000) on pöörduja, Jaan Ametnik (38001010000) vastas asutuse nimel.',
        ]);
        $this->redact($doc);

        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::PASS_REDACTED, $lia['outcome']);
        $text = collect($lia['sections'])->pluck('paragraphs')->flatten()->implode("\n") . $lia['outcome_reason'];
        foreach (['Mari Maasikas', 'Mari Maasikale', '48001010000', '38001010000'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $text);
        }
        $this->assertStringContainsString('Asutus vastas M. M. ja M. M. esitas täienduse', $text);
        $this->assertStringContainsString('Jaan Ametnik', $text);
        $this->assertStringContainsString('Dokumendi sisulisel läbivaatamisel 15.09.2026 otsustati jätta see avalikuks põhjendusega, et', $text);
        $this->assertSame($lia, $this->lia($doc));
    }

    public function test_unknown_groups_work_with_initials_and_unnamed_people(): void
    {
        $doc = $this->document([
            ['Kati Kask', Contexts::UNKNOWN, [], null],
            ['Ants Raud', Contexts::UNKNOWN, [], null],
            ['', Contexts::UNKNOWN, [], '48001010000'],
            ['', Contexts::UNKNOWN, [], null],
        ]);
        $text = implode("\n", $this->lia($doc)['sections'][1]['paragraphs']);
        $this->assertStringContainsString('Dokumendis esinevad määratlemata rollis K. K., A. R. ja 2 nimetut isikut.', $text);
        $doc->latestPiiAssessment->extraction->subjects()->whereNotNull('personal_code')->delete();
        $text = implode("\n", $this->lia($doc)['sections'][1]['paragraphs']);
        $this->assertStringContainsString('Dokumendis esinevad määratlemata rollis K. K., A. R. ja üks nimetu isik.', $text);
    }

    public function test_sensitive_and_reidentifiable_content_is_not_declared_anonymous_after_redaction(): void
    {
        $doc = $this->document([['Mari Maasikas', Contexts::PRIVATE_PERSON_SUBJECT, [], null]], [Flags::ETHNICITY_OR_LANGUAGE]);
        $doc->latestPiiAssessment->extraction->subjects()->first()->update([
            'subject_flags' => [Flags::HEALTH, Flags::CRIMINAL_MATTER, Flags::IDENTIFIABLE_BY_CONTEXT],
        ]);
        (new Assessor())->assess($doc);
        $this->redact($doc);

        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::PASS_REDACTED, $lia['outcome']);
        $text = collect($lia['sections'])->pluck('paragraphs')->flatten()->implode("\n");
        $this->assertStringContainsString('IKÜM art 9 lg 2', $text);
        $this->assertStringContainsString('IKÜM art 10 tingimused', $text);
        $this->assertStringContainsString('Keeleandmed üksi ei ole automaatselt eriliiki andmed.', $text);
        $this->assertStringContainsString('Rakendatud minimeerimine vähendab otsest seost eraasja ja isiku vahel', $text);
        $this->assertStringContainsString('seega on redigeeritud dokumendi avalikuks jätmine õigustatud IKÜM art 6 lg 1 p f alusel.', $lia['outcome_reason']);
        $this->assertStringNotContainsString('Lahendamata probleeme ei ole', $text);
        $this->assertStringNotContainsString('ainult isikustamata sisu', $text);
    }

    public function test_redaction_history_distinguishes_planned_actions_from_completion(): void
    {
        $doc = $this->document([['Mari Maasikas', Contexts::PRIVATE_PERSON_APPLICANT, [], null]]);
        $this->redact($doc);
        PiiRedaction::sole()->update([
            'files_status' => PiiRedaction::STATUS_PENDING,
            'plan' => ['replacements' => [['surface' => 'x']], 'files' => [['action' => 'REDACT_PDF']]],
        ]);

        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::FAIL, $lia['outcome']);
        $this->assertStringContainsString('failide töötlemise lõpetamist ei ole kinnitatud', $lia['outcome_reason']);
        $this->assertStringNotContainsString('ei õnnestunud', $lia['outcome_reason']);
        $text = implode("\n", $lia['sections'][2]['paragraphs']);
        $this->assertStringContainsString('Teksti redigeerimine on rakendatud, kuid failide töötlemise lõpetamist ei ole kinnitatud.', $text);
        $this->assertStringNotContainsString('failides on rakendatud redigeerimine', $text);
        $this->assertStringNotContainsString('Plaanis:', $text);
    }

    public function test_no_people_does_not_invent_officials_or_names(): void
    {
        $doc = $this->document([], [Flags::RESTRICTION_STAMP_PRESENT]);
        $this->restrictAtSource($doc);
        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::PASS, $lia['outcome']);
        $this->assertStringContainsString('Füüsilisi isikuid ei tuvastatud.', $lia['outcome_reason']);
        $this->assertStringNotContainsString('Tuvastatud rollid', $lia['outcome_reason']);
        $this->assertStringNotContainsString('kirjeldatud avalike rollidega', implode(' ', $lia['sections'][2]['paragraphs']));
        $this->assertStringNotContainsString('Lähteandmetes tuvastatud andmeliigid:', implode("\n", $lia['sections'][2]['paragraphs']));
    }

    public function test_each_context_has_its_own_roles_categories_and_balancing(): void
    {
        $doc = $this->document([
            ['Mari Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [
                ['type' => 'EMAIL', 'value' => 'mari@example.test', 'nature' => 'WORK'],
                ['type' => 'POSTAL_ADDRESS', 'value' => 'Kodu 1', 'nature' => 'PRIVATE'],
            ], '48001010000'],
            ['Kati Kask', Contexts::PRIVATE_PERSON_APPLICANT, [['type' => 'PHONE', 'value' => '5555555', 'nature' => 'PRIVATE']], null],
        ]);
        $subjects = $doc->latestPiiAssessment->extraction->subjects()->orderBy('id')->get();
        $subjects[0]->update(['role' => 'SIGNATORY', 'organisation' => 'SOM']);
        $subjects[1]->update(['role' => 'AUTHOR', 'organisation' => null, 'subject_flags' => [Flags::FAMILY_CIRCUMSTANCES]]);
        $this->redact($doc);
        PiiRedaction::sole()->update(['plan' => ['replacements' => [['surface' => 'Kodu 1', 'kind' => 'identifier']], 'files' => []]]);

        $lia = $this->lia($doc);
        $necessity = $lia['sections'][1]['paragraphs'];
        $this->assertCount(3, $necessity);
        $this->assertStringContainsString('Ta on dokumendi allkirjastaja', $necessity[1]);
        $this->assertStringContainsString('tegutseb organisatsiooni SOM nimel', $necessity[1]);
        $this->assertStringContainsString('tööalane e-posti aadress', $necessity[1]);
        $this->assertStringContainsString('eraviisiline postiaadress', $necessity[1]);
        $this->assertStringContainsString('on eemaldatud postiaadress', $necessity[1]);
        $this->assertStringContainsString('Ta on dokumendi koostaja', $necessity[2]);
        $this->assertStringContainsString('eraviisiline telefoninumber', $necessity[2]);
        $this->assertStringNotContainsString('telefoninumber', $necessity[1]);
        $balancing = $lia['sections'][2]['paragraphs'];
        $this->assertStringContainsString('Mari Ametnik', $balancing[0]);
        $this->assertStringNotContainsString('pereolud', $balancing[0]);
        $this->assertStringContainsString('K. K.', $balancing[1]);
        $this->assertStringContainsString('pereolud', $balancing[1]);
        $text = collect($lia['sections'])->pluck('paragraphs')->flatten()->implode("\n");
        foreach (['Kati Kask', '48001010000', 'Kodu 1', '5555555'] as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
        $this->assertSame(1, substr_count($text, 'IKÜM art 21'));
        $this->assertStringNotContainsString('CDN', $text);
    }

    public function test_sensitive_official_needs_review_and_keep_decision_remains_decisive(): void
    {
        $doc = $this->document([['Mari Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [], null]], [Flags::HEALTH]);
        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::NEEDS_REVIEW, $lia['outcome']);
        $this->assertStringContainsString('Läbivaataja peab selgitama', $lia['outcome_reason']);
        ReviewItem::create([
            'document_id' => $doc->id, 'status' => ReviewItem::STATUS_DECIDED, 'priority' => ReviewItem::PRIORITY_PII,
            'flags' => [], 'first_flagged_at' => now(), 'decided_at' => now(), 'decided_by' => 'haldus',
            'decision' => ReviewItem::DECISION_KEEP, 'decision_reason' => 'Tervisemärge puudutab üldist töökorraldust, mitte isiku tervist.',
        ]);
        $lia = $this->lia($doc);
        $this->assertSame(LegitimateInterest::PASS, $lia['outcome']);
        $this->assertStringContainsString('põhjendusega, et tervisemärge puudutab üldist töökorraldust, mitte isiku tervist.', implode(' ', $lia['sections'][2]['paragraphs']));
        $this->assertStringContainsString('seega on dokumendi avalikuks jätmine õigustatud', $lia['outcome_reason']);
        $this->assertStringNotContainsString('üksnes juhul', $lia['outcome_reason']);
    }

    public function test_source_restriction_preserves_its_ground_without_inventing_personal_data(): void
    {
        $doc = $this->document([['Mari Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [], null]], [Flags::SCANNED_OR_IMAGE_CONTENT]);
        $this->restrictAtSource($doc);
        DocumentRemoteState::sole()->update(['personal_data_restriction' => false, 'remote_restriction_basis' => 'AvTS § 35 lg 1 p 17']);
        DocumentStatusChange::sole()->update(['personal_data' => false, 'basis' => 'AvTS § 35 lg 1 p 17']);
        $text = implode(' ', $this->lia($doc)['sections'][2]['paragraphs']);
        $this->assertStringContainsString('AvTS § 35 lg 1 p 17', $text);
        $this->assertStringContainsString('millist huvi see piirang kaitseb', $text);
        $this->assertStringNotContainsString('Piirang väljendab allika hinnangut isikuandmete kaitse vajadusele', $text);
    }

    public function test_summary_fragments_and_sentences_use_the_same_template(): void
    {
        $doc = $this->document([]);
        $extraction = $doc->latestPiiAssessment->extraction;
        foreach (['Hankeleping asutuse ruumide koristamiseks', 'Asutus sõlmis koristuslepingu.'] as $summary) {
            $extraction->update(['summary' => $summary]);
            $this->assertStringStartsWith(rtrim($summary, '.') . '. Puudutab avaliku raha kasutamist. Selle teabe avaldamine teenib', $this->lia($doc)['sections'][0]['paragraphs'][0]);
        }
    }

    public function test_preview_shows_on_the_document_panel_and_in_the_list(): void
    {
        $doc = $this->document([['Mari Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [], null]]);
        $this->withSession(['is_admin' => true])->get(route('document', $doc))->assertOk()
            ->assertSee('Õigustatud huvi hinnang')->assertSee('4. Järeldus')->assertSee('Läbib');
        $this->withSession(['is_admin' => true])->get(route('pii.index'))->assertOk()
            ->assertSee('Õ. huvi')->assertSee('#oigustatud-huvi', false);
        $this->flushSession();
        $this->get(route('document', $doc))->assertOk()->assertDontSee('Õigustatud huvi hinnang');
    }
}
