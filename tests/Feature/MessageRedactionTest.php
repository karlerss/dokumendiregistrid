<?php

namespace Tests\Feature;

use App\Lib\Compliance\ReviewQueue;
use App\Lib\Pii\MessageRenderer;
use App\Lib\Pii\PdfRedactor;
use App\Lib\Pii\RedactionPlanner;
use App\Mcp\Servers\ComplianceServer;
use App\Mcp\Tools\DecideTool;
use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\Organisation;
use App\Models\PiiRedaction;
use App\Models\ReviewItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class MessageRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const EML = "From: Mari Maasikas <mari@example.com>\r\n"
        . "To: Ants Ametnik <ants@rmk.ee>\r\n"
        . "Subject: Taotlus - Mari Maasikas\r\n"
        . "Date: Mon, 1 Jun 2026 10:00:00 +0300\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=utf-8\r\n\r\n"
        . "<p>Tere! <b>Mari</b> Maasikas palub parkimiskohta. <img src=\"http://example.com/x.png\"> "
        . "<a href=\"mailto:mari@example.com\">Vasta</a></p><script>alert(1)</script>";

    private Document $doc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2');
        Storage::fake('r2_private');
        $org = Organisation::create(['name' => 'RMK', 'slug' => 'rmk', 'registry_base_uri' => 'https://adr.rmk.ee/', 'fetcher_type' => 'rmk']);
        $this->doc = Document::create([
            'organisation_id' => $org->id, 'url' => 'https://adr.rmk.ee/dokument/1', 'original_id' => '1',
            'title' => 'Pöördumine', 'reference' => '1-5/1', 'registration_date' => '2026-06-01', 'type' => 'Kiri', 'restriction' => 'Avalik',
        ]);
        // PDF redaction tool: pass the rendered PDF through unchanged.
        $this->app->bind(PdfRedactor::class, fn() => new class implements PdfRedactor {
            public function redact(string $inputPath, string $outputPath, array $forms): array
            {
                copy($inputPath, $outputPath);
                return ['hits' => array_fill_keys($forms, 0), 'pages' => 1];
            }

            public function convertToPdf(string $inputPath, string $outputDir): string
            {
                throw new \RuntimeException('not needed');
            }

            public function convertTo(string $inputPath, string $outputDir, string $format): string
            {
                throw new \RuntimeException('not needed');
            }
        });
    }

    private function file(string $name, string $bytes, array $attrs = []): File
    {
        $key = 'K' . random_int(100000, 999999) . '/' . $name;
        Storage::disk('r2')->put($key, $bytes);
        return File::create(array_merge(['document_id' => $this->doc->id, 'name' => $name, 'location' => $key], $attrs));
    }

    /** What EmlParser stores for EML: body only, headers are not in contents. */
    private function eml(): File
    {
        return $this->file('kiri.eml', self::EML, [
            'contents' => 'Tere! Mari Maasikas palub parkimiskohta. Vasta',
            'html' => '<p>Tere! <b>Mari</b> Maasikas palub parkimiskohta. <img src="http://example.com/x.png"> <a href="mailto:mari@example.com">Vasta</a></p>',
        ]);
    }

    private function replacements(): array
    {
        return (new RedactionPlanner())->planFromStrings($this->doc->fresh(), ['Mari Maasikas', 'mari@example.com'])['replacements'];
    }

    public function test_eml_rendition_has_headers_and_body_without_the_strings_or_active_content(): void
    {
        $file = $this->eml();

        $html = app(MessageRenderer::class)->render($file, $this->replacements());

        $this->assertStringNotContainsString('Maasikas', $html);
        $this->assertStringNotContainsString('mari@example.com', $html);
        $this->assertStringContainsString('Ants Ametnik', $html, 'unplanned header text stays');
        $this->assertStringContainsString('Taotlus - [eemaldatud]', $html);
        $this->assertStringContainsString('parkimiskohta', $html);
        $this->assertStringContainsString('[pilt]', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('http://example.com', $html);
        $this->assertStringNotContainsString('href', $html);
    }

    public function test_html_file_is_rendered_from_the_original_page(): void
    {
        $file = $this->file('leht.html', '<html><head><title>Mari Maasikas</title></head><body><h1>Teade</h1><p>Kontakt: Mari Maasikas, mari@example.com</p></body></html>');

        $html = app(MessageRenderer::class)->render($file, $this->replacements());

        $this->assertStringContainsString('Teade', $html);
        $this->assertStringContainsString('Kontakt: [eemaldatud], [eemaldatud]', $html);
        $this->assertStringNotContainsString('Maasikas', $html);
    }

    public function test_msg_is_rendered_from_its_stored_message_html(): void
    {
        $file = $this->file('kiri.msg', 'binary-outlook', [
            'html' => '<table><tr><td>From:</td><td>Mari Maasikas</td></tr></table><p>Palun vastake.</p>',
            'contents' => 'From: Mari Maasikas Palun vastake.',
        ]);

        $html = app(MessageRenderer::class)->render($file, $this->replacements());

        $this->assertStringContainsString('Palun vastake.', $html);
        $this->assertStringNotContainsString('Maasikas', $html);
    }

    public function test_rendition_becomes_a_pdf(): void
    {
        $file = $this->eml();
        $out = tempnam(sys_get_temp_dir(), 'pdf');

        $renderer = app(MessageRenderer::class);
        $renderer->toPdf($renderer->render($file, $this->replacements()), $out);

        $this->assertStringStartsWith('%PDF', file_get_contents($out));
        @unlink($out);
    }

    public function test_planner_renders_messages_and_html_and_still_withholds_signed_containers(): void
    {
        // Name only in the EML headers: the stored body does not mention it.
        $headerOnly = $this->file('ainult-pais.eml', str_replace('Mari</b> Maasikas', 'Tundmatu</b>', self::EML), ['contents' => 'Tere! Tundmatu palub parkimiskohta.']);
        $page = $this->file('leht.html', '<p>Mari Maasikas</p>');
        $msg = $this->file('kiri.msg', 'x', ['contents' => 'Vastus', 'html' => '<p>Vastus</p>']);
        $this->file('lisa.pdf', '%PDF', ['contents' => str_repeat('Lisa: Mari Maasikas taotlus. ', 10), 'parent_id' => $msg->id]);
        $asice = $this->file('otsus.asice', 'zip', ['contents' => null]);
        $this->file('otsus.pdf', '%PDF', ['contents' => str_repeat('Otsus Mari Maasikas kohta. ', 10), 'parent_id' => $asice->id]);

        $plan = (new RedactionPlanner())->planFromStrings($this->doc->fresh(), ['Mari Maasikas']);
        $action = fn(File $f) => $plan['files'][(string)$f->id]['action'];

        $this->assertSame(RedactionPlanner::ACTION_RENDER_AND_REDACT, $action($headerOnly), 'touched through its headers');
        $this->assertSame(RedactionPlanner::ACTION_RENDER_AND_REDACT, $action($page), 'touched through the original page text');
        $this->assertSame(RedactionPlanner::ACTION_RENDER_AND_REDACT, $action($msg), 'message whose attachment is touched');
        $this->assertSame(RedactionPlanner::ACTION_WITHHOLD, $action($asice), 'signed container');
    }

    private function zip(string $name, array $parts, array $attrs = []): File
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($parts as $part => $xml) {
            $zip->addFromString($part, $xml);
        }
        $zip->close();
        $file = $this->file($name, file_get_contents($tmp), $attrs);
        @unlink($tmp);
        return $file;
    }

    public function test_office_files_are_touched_through_headers_footers_comments_and_cells(): void
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        // soffice --cat gives the parser the body only; the name is in the header.
        $header = $this->zip('taotlus.docx', [
            'word/document.xml' => "<w:document $w><w:body><w:p><w:r><w:t>Palun parkimiskohta.</w:t></w:r></w:p></w:body></w:document>",
            'word/header1.xml' => "<w:hdr $w><w:p><w:r><w:t>Koostaja: Mari Maasikas</w:t></w:r></w:p></w:hdr>",
        ], ['contents' => str_repeat('Palun parkimiskohta. ', 10)]);
        // Name split across formatting runs inside a comment.
        $split = $this->zip('vastus.docx', [
            'word/document.xml' => "<w:document $w><w:body><w:p><w:r><w:t>Vastus.</w:t></w:r></w:p></w:body></w:document>",
            'word/comments.xml' => "<w:comments $w><w:comment><w:p><w:r><w:t>Ma</w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>ri Maa</w:t></w:r><w:r><w:t>sikas</w:t></w:r></w:p></w:comment></w:comments>",
        ], ['contents' => str_repeat('Vastus. ', 20)]);
        // Spreadsheets are stored without text at all.
        $sheet = $this->zip('peakasutajad.xlsx', [
            'xl/sharedStrings.xml' => '<sst><si><t>Nimi</t></si><si><r><t>Mari</t></r><r><t xml:space="preserve"> Maasikas</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c t="s"><v>0</v></c><c t="s"><v>1</v></c></row></sheetData></worksheet>',
        ], ['contents' => null]);
        $clean = $this->zip('muu.docx', [
            'word/document.xml' => "<w:document $w><w:body><w:p><w:r><w:t>Muu tekst, Tammsaare.</w:t></w:r></w:p></w:body></w:document>",
        ], ['contents' => null]);

        $plan = (new RedactionPlanner())->planFromStrings($this->doc->fresh(), ['Mari Maasikas']);
        $action = fn(File $f) => $plan['files'][(string)$f->id]['action'];

        $this->assertSame(RedactionPlanner::ACTION_CONVERT_AND_REDACT, $action($header), 'name only in the Word header');
        $this->assertSame(RedactionPlanner::ACTION_CONVERT_AND_REDACT, $action($split), 'name split across runs in a comment');
        $this->assertSame(RedactionPlanner::ACTION_CONVERT_AND_REDACT, $action($sheet), 'name in shared strings');
        $this->assertSame(RedactionPlanner::ACTION_KEEP, $action($clean));
    }

    public function test_decide_redact_finds_strings_that_only_an_xlsx_carries(): void
    {
        $this->zip('peakasutajad.xlsx', ['xl/sharedStrings.xml' => '<sst><si><t>Mari Maasikas</t></si></sst>'], ['contents' => null]);
        $item = app(ReviewQueue::class)->flag($this->doc, null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'AKI 456', null);

        $plan = $this->tool(['item_id' => $item->id, 'decision' => 'redact', 'reason' => 'erasure request', 'strings' => ['Mari Maasikas']]);

        $this->assertSame([], $plan['effects']['strings_not_found']);
        $this->assertSame('CONVERT_AND_REDACT', $plan['effects']['files'][0]['action']);
    }

    // --- through the review queue -------------------------------------------

    private function tool(array $args): array
    {
        $out = null;
        ComplianceServer::tool(DecideTool::class, $args)
            ->assertHasNoErrors()
            ->assertStructuredContent(function (AssertableJson $json) use (&$out) {
                $out = $json->toArray();
                $json->etc();
            });
        return $out;
    }

    public function test_decide_redact_replaces_the_eml_with_a_redacted_pdf(): void
    {
        $file = $this->eml();
        $this->doc->ftsIndexSingle();
        DocumentRemoteState::reconcile();
        $change = DocumentStatusChange::create(['document_id' => $this->doc->id, 'occurred_at' => now(), 'to_status' => 'restricted', 'personal_data' => true, 'http_status' => 200]);
        $item = app(ReviewQueue::class)->flag($this->doc, null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'kiri', null, ['public_sector' => false, 'personal_data' => true]);
        $args = ['item_id' => $item->id, 'decision' => 'redact', 'reason' => 'Eraisiku andmed; dokument ise avalik huvi.', 'strings' => ['Mari Maasikas', 'mari@example.com', 'Olematu Nimi']];

        $plan = $this->tool($args);
        $this->assertTrue($plan['dry_run']);
        $this->assertSame(['Olematu Nimi'], $plan['effects']['strings_not_found']);
        $this->assertEqualsCanonicalizing(['Mari Maasikas', 'mari@example.com'], array_column($plan['effects']['replacements'], 'surface'));
        $this->assertSame('RENDER_AND_REDACT', $plan['effects']['files'][0]['action']);
        $this->assertArrayNotHasKey('plan', $plan['effects']);
        Storage::disk('r2')->assertExists($file->location);

        // queue is sync in tests: the redaction job runs here
        $done = $this->tool($args + ['confirm' => true, 'plan_id' => $plan['plan_id']]);
        $this->assertSame('redact', $done['decision']);

        $redaction = PiiRedaction::sole();
        $this->assertSame(PiiRedaction::STATUS_APPLIED, $redaction->text_status, json_encode($redaction->log));
        $this->assertSame(PiiRedaction::STATUS_APPLIED, $redaction->files_status, json_encode($redaction->log));
        $this->assertStringStartsWith('mcp:', $redaction->applied_by);

        $file->refresh();
        $this->assertStringNotContainsString('Maasikas', $file->contents);
        $this->assertTrue($file->original_withheld);
        Storage::disk('r2')->assertMissing($file->location);
        Storage::disk('r2_private')->assertExists($file->location);
        $this->assertStringEndsWith('/kiri.pdf', $file->redacted_location);
        $this->assertStringStartsWith('%PDF', Storage::disk('r2')->get($file->redacted_location));
        $this->assertStringContainsString($file->redacted_location, $file->url);

        $this->assertTrue($this->doc->fresh()->visible, 'redaction keeps the document published');
        $this->assertSame('redacted', $change->fresh()->action);
    }

    public function test_redact_without_assessment_needs_strings_and_refuses_a_second_redaction(): void
    {
        $this->eml();
        $item = app(ReviewQueue::class)->flag($this->doc, null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, 'kiri', null);

        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'redact', 'reason' => 'test reason'])
            ->assertHasErrors(['no PII assessment']);
        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'redact', 'reason' => 'test reason', 'strings' => ['Keegi Muu']])
            ->assertHasErrors(['replaces nothing']);
        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'redact', 'reason' => 'test reason', 'strings' => ['Mari Maasikas'], 'confirm' => true])
            ->assertHasErrors(['plan_id']);

        PiiRedaction::create(['document_id' => $this->doc->id, 'applied_by' => 'admin', 'plan' => [], 'text_status' => 'applied', 'files_status' => 'applied']);
        ComplianceServer::tool(DecideTool::class, ['item_id' => $item->id, 'decision' => 'redact', 'reason' => 'test reason', 'strings' => ['Mari Maasikas']])
            ->assertHasErrors(['already has a redaction']);
    }
}
