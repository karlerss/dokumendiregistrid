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
        return (new LegitimateInterest())->assess($doc->fresh());
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
        $this->assertStringContainsString('Ametnik tööülesannetes (1): Mari Ametnik.', $text);
        $this->assertStringContainsString('14.09.2026 allikas piiras juurdepääsu: Avalik → AK (AvTS § 35 lg 1 p 12, isikuandmete kaitse).', $text);
        $this->assertStringContainsString('See seob avaliku sektori asutusi, mitte kolmandaid isikuid.', $text);
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
        $this->assertStringContainsString('Eraisik: pöörduja (1): M. M.', $text);
        $this->assertStringContainsString('nimi on asendatud initsiaalidega', $text);
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

    public function test_preview_shows_on_the_document_panel_and_in_the_list(): void
    {
        $doc = $this->document([['Mari Ametnik', Contexts::PUBLIC_OFFICIAL_WORK_MATTER, [], null]]);
        $this->withSession(['is_admin' => true])->get(route('document', $doc))->assertOk()
            ->assertSee('Õigustatud huvi hinnang')->assertSee('6. Järeldus')->assertSee('Läbib');
        $this->withSession(['is_admin' => true])->get(route('pii.index'))->assertOk()
            ->assertSee('Õ. huvi')->assertSee('#oigustatud-huvi', false);
        $this->flushSession();
        $this->get(route('document', $doc))->assertOk()->assertDontSee('Õigustatud huvi hinnang');
    }
}
