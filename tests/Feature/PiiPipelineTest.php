<?php

namespace Tests\Feature;

use App\Jobs\AssessDocumentPii;
use App\Jobs\ExtractDocumentPii;
use App\Lib\LLM\AiProvider;
use App\Lib\Pii\Assessor;
use App\Lib\Pii\Contexts;
use App\Lib\Pii\Extractor;
use App\Lib\Pii\Flags;
use App\Lib\Pii\PdfRedactor;
use App\Lib\Pii\RedactionPlanner;
use App\Lib\Pii\Redactor;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\File;
use App\Models\Organisation;
use App\Models\PiiAssessment;
use App\Models\PiiExtraction;
use App\Models\PiiRedaction;
use App\Models\PiiSubject;
use App\Models\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PiiPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::create(['name' => 'Transpordiamet', 'slug' => 'ta', 'registry_base_uri' => 'https://adr.rik.ee/ta/', 'fetcher_type' => 'delta-adr']);
        Storage::fake('r2');
        Storage::fake('r2_private');
    }

    /** A document with one PDF whose text names a private person and an official. */
    private function document(): Document
    {
        $doc = Document::create([
            'organisation_id' => $this->org->id,
            'url' => 'https://adr.rik.ee/ta/dokument/1',
            'original_id' => '1',
            'title' => 'Vastus M. M. pöördumisele',
            'reference' => '1-2/3',
            'registration_date' => '2025-05-05',
            'type' => 'Väljaminev kiri',
            'restriction' => 'Avalik',
            'to' => 'M. M.',
            'responsible' => 'Ants Ametnik',
        ]);
        Storage::disk('r2')->put('abc/vastus.pdf', '%PDF-1.4 fake');
        $file = File::create([
            'document_id' => $doc->id,
            'name' => 'Vastus_M.Maasikas.pdf',
            'location' => 'abc/vastus.pdf',
            'contents' => "Austatud Mari Maasikas\n\nTeie 01.05.2025 pöördumine (isikukood 49403136515) on läbi vaadatud. Elate aadressil Pihlaka tn 3, Tammsaare küla. Vt ka Mari Maasikase varasem kiri ja Tammsaare muuseum.\n\nLugupidamisega\nAnts Ametnik\nants.ametnik@transpordiamet.ee\nTeine isik 37605030299 mainitud.",
            'html' => '<p>Austatud <b>Mari</b> Maasikas</p><p>isikukood 49403136515</p><p>Ants Ametnik</p><p>37605030299</p>',
            'parsed_with' => \App\Lib\Parser\PdfParser::class,
        ]);
        Signature::create(['file_id' => $file->id, 'name' => 'Ants Ametnik', 'pno' => '37605030299', 'signing_time' => now()]);
        DocumentRemoteState::reconcile();
        DocumentRemoteState::where('document_id', $doc->id)->update(['personal_data_restriction' => true, 'remote_status' => 'restricted']);
        $doc->ftsIndexSingle();
        return $doc->fresh();
    }

    private function fakeAi(array $response): void
    {
        $this->app->bind(AiProvider::class, fn() => new class($response) implements AiProvider {
            public array $calls = [];

            public function __construct(private array $response)
            {
            }

            public function getJson(string $systemPrompt, string $userPrompt, array $jsonSchema): array
            {
                return $this->response;
            }

            public function getJsonWithUsage(string $systemPrompt, string $userPrompt, array $jsonSchema): array
            {
                $this->calls[] = $userPrompt;
                return ['data' => $this->response, 'input_tokens' => 1000, 'output_tokens' => 100, 'model' => 'fake'];
            }
        });
    }

    private function modelResponse(): array
    {
        return [
            'subjects' => [
                [
                    'name' => 'Mari Maasikas', 'personal_code' => '4940313 6515',
                    'surface_forms' => ['Mari Maasikas', 'M. M.', 'M.Maasikas', 'Maasikale'],
                    'linked_metadata_initials' => 'M. M.',
                    'context' => Contexts::PRIVATE_PERSON_APPLICANT, 'role' => 'ADDRESSEE', 'organisation' => null,
                    'identifiers' => [
                        ['type' => 'POSTAL_ADDRESS', 'value' => 'Pihlaka tn 3, Tammsaare küla', 'nature' => 'PRIVATE'],
                        ['type' => 'PHONE', 'value' => '+372 5555 5555', 'nature' => 'PRIVATE'],
                    ],
                    'subject_flags' => [Flags::HOME_LOCATION_DETAIL],
                    'evidence' => 'Austatud Mari Maasikas', 'confidence' => 'HIGH',
                ],
                [
                    'name' => 'Ants Ametnik', 'personal_code' => null,
                    'surface_forms' => ['Ants Ametnik', 'ants.ametnik'],
                    'linked_metadata_initials' => null,
                    'context' => Contexts::PUBLIC_OFFICIAL_WORK_MATTER, 'role' => 'SIGNATORY', 'organisation' => 'Transpordiamet',
                    'identifiers' => [['type' => 'EMAIL', 'value' => 'ants.ametnik@transpordiamet.ee', 'nature' => 'WORK']],
                    'subject_flags' => [], 'evidence' => 'Lugupidamisega Ants Ametnik', 'confidence' => 'HIGH',
                ],
                [
                    'name' => 'Väljamõeldud Isik', 'personal_code' => null,
                    'surface_forms' => ['Väljamõeldud Isik'], 'linked_metadata_initials' => null,
                    'context' => Contexts::PRIVATE_PERSON_THIRD_PARTY, 'role' => 'MENTIONED', 'organisation' => null,
                    'identifiers' => [], 'subject_flags' => [], 'evidence' => 'x', 'confidence' => 'LOW',
                ],
            ],
            'legal_entities' => [['name' => 'Transpordiamet', 'registry_code' => '70001490', 'kind' => 'PUBLIC_BODY']],
            'document' => [
                'matter' => 'INFORMATION_REQUEST', 'summary' => 'Vastus eraisiku pöördumisele.', 'flags' => [Flags::CONFLICT_OR_DISPUTE],
                'restriction_stamp_holder' => null, 'restriction_stamp_basis' => null,
                'public_interest' => 'MEDIUM', 'public_interest_reason' => 'Rutiinne kirjavahetus.',
            ],
        ];
    }

    public function test_extraction_grounds_merges_sweeps_and_never_touches_visibility(): void
    {
        Queue::fake();
        $doc = $this->document();
        $this->fakeAi($this->modelResponse());

        $this->assertSame(1, Extractor::selectionQuery()->count());
        $row = Extractor::request($doc, PiiExtraction::REQUESTED_BY_WORKER);
        (new ExtractDocumentPii($row->id))->handle(app(Extractor::class));

        $row->refresh();
        $this->assertSame(PiiExtraction::STATUS_DONE, $row->status);
        $this->assertSame(1100, $row->input_tokens + $row->output_tokens);
        $this->assertSame('INFORMATION_REQUEST', $row->matter);
        $this->assertSame(0, Extractor::selectionQuery()->count(), 'extracted documents leave the selection');
        Queue::assertPushed(AssessDocumentPii::class);

        $subjects = $row->subjects()->orderBy('id')->get();
        $this->assertCount(3, $subjects, 'hallucinated subject dropped, unattributed code swept in');

        $mari = $subjects->firstWhere('name', 'Mari Maasikas');
        $this->assertSame('49403136515', $mari->personal_code, 'code normalised');
        $this->assertEqualsCanonicalizing(['Mari Maasikas', 'M. M.', 'M.Maasikas', '49403136515'], $mari->verifiedForms());
        $this->assertSame(['Maasikale'], $mari->unverified_forms);
        $ids = collect($mari->identifiers);
        $this->assertTrue($ids->firstWhere('type', 'POSTAL_ADDRESS')['verified']);
        $this->assertFalse($ids->firstWhere('type', 'PHONE')['verified']);

        $swept = $subjects->firstWhere('personal_code', '37605030299');
        $this->assertNotNull($swept, 'personal code in text that the model did not attribute');
        $this->assertSame(Contexts::UNKNOWN, $swept->context);
        $this->assertNull($swept->name);

        $this->assertCount(1, $row->raw_response['dropped_subjects']);
        $this->assertTrue($doc->fresh()->visible, 'extraction never changes visibility');
    }

    public function test_assessment_planning_redaction_and_revert(): void
    {
        Queue::fake();
        $doc = $this->document();
        $this->fakeAi($this->modelResponse());
        $row = Extractor::request($doc, PiiExtraction::REQUESTED_BY_ADMIN);
        app(Extractor::class)->run($row);

        // Assessment: private person with re-id flag but sensitivity is only doc-level CONFLICT → HIGH (reid + sensitive)
        $assessment = (new Assessor())->assess($doc);
        $this->assertSame(PiiAssessment::BAND_HIGH, $assessment->band);
        $this->assertSame($assessment->id, (new Assessor())->assess($doc)->id, 'idempotent');

        // Admin overrides the swept UNKNOWN subject to an official → still HIGH from Mari; re-assess creates a new row
        $swept = PiiSubject::where('personal_code', '37605030299')->first();
        $swept->forceFill(['context_override' => Contexts::PUBLIC_OFFICIAL_WORK_MATTER, 'overridden_at' => now()->addSecond()])->save();
        $assessment2 = (new Assessor())->assess($doc);
        $this->assertNotSame($assessment->id, $assessment2->id);
        $this->assertTrue($doc->fresh()->visible);

        // Plan
        $subjects = $row->subjects()->get();
        $plan = (new RedactionPlanner())->plan($doc->fresh(), $assessment2, $subjects);
        $surfaces = array_column($plan['replacements'], 'surface');
        $this->assertContains('Mari Maasikas', $surfaces);
        $this->assertContains('49403136515', $surfaces);
        $this->assertContains('Pihlaka tn 3, Tammsaare küla', $surfaces);
        $this->assertNotContains('Ants Ametnik', $surfaces, 'official kept');
        $this->assertNotContains('37605030299', $surfaces, 'overridden to official: code kept');
        $this->assertSame('M. M.', collect($plan['replacements'])->firstWhere('surface', 'Mari Maasikas')['replacement']);
        $fileId = (string)$doc->files->first()->id;
        $this->assertSame(RedactionPlanner::ACTION_REDACT_PDF, $plan['files'][$fileId]['action']);

        // Redact (text + files) with a fake PDF tool
        $this->app->bind(PdfRedactor::class, fn() => new class implements PdfRedactor {
            public function redact(string $inputPath, string $outputPath, array $forms): array
            {
                file_put_contents($outputPath, '%PDF redacted');
                return ['hits' => array_fill_keys($forms, 1), 'pages' => 1];
            }

            public function convertToPdf(string $inputPath, string $outputDir): string
            {
                throw new \RuntimeException('not needed');
            }
        });
        $redaction = PiiRedaction::create(['document_id' => $doc->id, 'assessment_id' => $assessment2->id, 'applied_by' => 'admin', 'plan' => $plan]);
        (new \App\Jobs\RedactDocument($redaction->id))->handle(app(Redactor::class));

        $redaction->refresh();
        $this->assertSame(PiiRedaction::STATUS_APPLIED, $redaction->text_status, json_encode($redaction->log));
        $this->assertSame(PiiRedaction::STATUS_APPLIED, $redaction->files_status, json_encode($redaction->log));

        $fresh = $doc->fresh();
        $file = $fresh->files->first();
        $this->assertStringNotContainsString('Mari Maasikas', $file->contents);
        $this->assertStringContainsString('Austatud M. M.', $file->contents);
        $this->assertStringContainsString('[isikukood eemaldatud]', $file->contents);
        $this->assertStringContainsString('Ants Ametnik', $file->contents, 'official kept');
        $this->assertStringContainsString('Tammsaare muuseum', $file->contents, 'boundary-safe: only the address form was removed, other text stays');
        $this->assertStringContainsString('Vt ka M. M. varasem kiri', $file->contents, 'unlisted inflected form of a full name is covered');
        $this->assertStringNotContainsString('Pihlaka tn 3', $file->contents);
        $this->assertSame('Vastus_M. M..pdf', $file->name);
        $this->assertStringNotContainsString('Maasikas', $file->html, 'html regenerated because <b> split the name');
        $this->assertNotNull($fresh->redacted_at);
        $this->assertNull($fresh->ai_summary);
        $this->assertTrue($fresh->visible, 'redaction never changes visibility');

        // originals moved, redacted copy served
        $this->assertTrue($file->original_withheld);
        $this->assertSame('abc/vastus.pdf', $file->original_private_location);
        Storage::disk('r2_private')->assertExists('abc/vastus.pdf');
        Storage::disk('r2')->assertMissing('abc/vastus.pdf');
        Storage::disk('r2')->assertExists($file->redacted_location);
        $this->assertStringContainsString($file->redacted_location, $file->url);

        // FTS no longer finds the name
        $hits = \DB::table('fts_documents')->whereFullText('fts_documents', '"Maasikas"')->count();
        $this->assertSame(0, $hits);

        // Revert
        app(Redactor::class)->revert($redaction, 'admin');
        $reverted = $doc->fresh();
        $rf = $reverted->files->first();
        $this->assertStringContainsString('Mari Maasikas', $rf->contents);
        $this->assertSame('Vastus_M.Maasikas.pdf', $rf->name);
        $this->assertFalse((bool)$rf->original_withheld);
        $this->assertNull($rf->redacted_location);
        Storage::disk('r2')->assertExists('abc/vastus.pdf');
        Storage::disk('r2_private')->assertMissing('abc/vastus.pdf');
        $this->assertNull($reverted->redacted_at);
        $this->assertNotNull($redaction->fresh()->reverted_at);
    }

    public function test_text_step_fails_and_rolls_back_when_a_form_survives(): void
    {
        $doc = $this->document();
        $plan = [
            'replacements' => [['subject_id' => 1, 'surface' => 'Maasikas', 'replacement' => 'M.', 'kind' => 'name']],
            'files' => [], 'clear_ai_summary' => false,
        ];
        // "Maasikas" occurs in the file name as "M.Maasikas" — boundary replace handles it there,
        // but simulate a leftover by making the plan's surface something that only partially matches.
        $plan['replacements'][0]['surface'] = 'Mari Maasikas';
        $doc->files->first()->update(['html' => '<p>Mari</p><p>Maasikas</p>']);
        $redaction = PiiRedaction::create(['document_id' => $doc->id, 'applied_by' => 'admin', 'plan' => $plan]);
        $ok = app(Redactor::class)->applyText($redaction);
        $this->assertTrue($ok, 'html regenerated from contents; no leftover');
        $this->assertStringNotContainsString('Maasikas', $doc->fresh()->files->first()->html);
    }

    public function test_enqueue_redispatches_stale_redactions_once(): void
    {
        Queue::fake();
        $doc = $this->document();
        $r = PiiRedaction::create(['document_id' => $doc->id, 'applied_by' => 'admin', 'plan' => ['replacements' => [], 'files' => []]]);
        $this->artisan('pii:enqueue')->assertSuccessful();
        Queue::assertNotPushed(\App\Jobs\RedactDocument::class);

        PiiRedaction::where('id', $r->id)->update(['updated_at' => now()->subHours(2)]);
        $this->artisan('pii:enqueue')->assertSuccessful();
        Queue::assertPushed(\App\Jobs\RedactDocument::class, 1);
        $this->assertSame('stale', $r->fresh()->log[0]['outcome']);

        $this->artisan('pii:enqueue')->assertSuccessful();
        Queue::assertPushed(\App\Jobs\RedactDocument::class, 1);
    }

    public function test_enqueue_respects_cap_and_admin_requests(): void
    {
        Queue::fake();
        $doc = $this->document();
        config(['pii.daily_token_cap' => 10]);
        PiiExtraction::create([
            'document_id' => $doc->id, 'model' => 'x', 'prompt_version' => 99, 'schema_version' => 1,
            'status' => PiiExtraction::STATUS_DONE, 'requested_by' => 'worker', 'input_tokens' => 20, 'output_tokens' => 0,
        ]);
        $this->artisan('pii:enqueue')->assertSuccessful();
        Queue::assertNotPushed(ExtractDocumentPii::class);

        Extractor::request($doc, PiiExtraction::REQUESTED_BY_ADMIN);
        $this->artisan('pii:enqueue')->assertSuccessful();
        Queue::assertPushed(ExtractDocumentPii::class, 1);
    }
}
