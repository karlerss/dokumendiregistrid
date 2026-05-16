<?php

namespace Tests\Feature;

use App\Lib\Parser\AsiceParser;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReplaceFileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Copy a fixture file to a temp path so the original is not affected.
     */
    private function tempCopy(string $fixturePath): string
    {
        $tmp = sys_get_temp_dir() . '/' . basename($fixturePath);
        copy($fixturePath, $tmp);
        return $tmp;
    }

    private function createDocumentWithAsiceFiles(): Document
    {
        $org = Organisation::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'registry_base_uri' => 'https://test.example.com',
        ]);

        $document = Document::create([
            'organisation_id' => $org->id,
            'url' => 'https://test.example.com/doc/1',
            'original_id' => '1',
            'title' => 'Test Document',
            'reference' => 'REF-1',
            'registration_date' => '2024-01-01',
            'type' => 'Test',
            'restriction' => 'Avalik',
        ]);

        // Parse the asice fixture — produces [asice_file, pdf_child]
        $files = (new AsiceParser(base_path('tests/__fixtures/50k.asice')))->parse();

        $document->files()->saveMany($files);
        $document->ftsIndexSingle();

        return $document;
    }

    /** @test */
    public function it_replaces_a_child_file_preserving_parent(): void
    {
        $document = $this->createDocumentWithAsiceFiles();

        // The asice has 2 files: asice container (parent) and pdf (child)
        $asiceFile = $document->files()->whereNull('parent_id')->first();
        $pdfChild = $document->files()->where('parent_id', $asiceFile->id)->first();

        $this->assertNotNull($asiceFile);
        $this->assertNotNull($pdfChild);
        $this->assertEquals('50k.pdf', $pdfChild->name);

        $oldPdfId = $pdfChild->id;

        // Replace the child PDF with a different PDF
        $newFiles = $pdfChild->replaceWith($this->tempCopy(base_path('tests/__fixtures/pii_ex/julg.pdf')));

        // Old file should be deleted
        $this->assertNull(File::find($oldPdfId));

        // New file should exist and have the same parent
        $this->assertNotEmpty($newFiles);
        $newFile = $newFiles[0];
        $this->assertEquals($asiceFile->id, $newFile->parent_id);
        $this->assertEquals('julg.pdf', $newFile->name);

        // New file should belong to the document
        $this->assertEquals($document->id, $newFile->document_id);

        // Document should still have the asice file
        $this->assertNotNull(File::find($asiceFile->id));
    }

    /** @test */
    public function it_replaces_a_root_file_with_null_parent(): void
    {
        $document = $this->createDocumentWithAsiceFiles();

        $asiceFile = $document->files()->whereNull('parent_id')->first();
        $childCount = $document->files()->where('parent_id', $asiceFile->id)->count();
        $oldAsiceId = $asiceFile->id;
        $oldTotalFiles = $document->files()->count();

        // Replace the root asice file — this should also delete its children
        $newFiles = $asiceFile->replaceWith($this->tempCopy(base_path('tests/__fixtures/pii_ex/julg.pdf')));

        // Old asice and its children should be deleted
        $this->assertNull(File::find($oldAsiceId));
        $this->assertEquals(0, File::where('parent_id', $oldAsiceId)->count());

        // New file should have null parent (root level)
        $newFile = $newFiles[0];
        $this->assertNull($newFile->parent_id);
        $this->assertEquals($document->id, $newFile->document_id);

        // Total file count: old files minus asice minus children plus new files
        $expectedCount = $oldTotalFiles - 1 - $childCount + count($newFiles);
        $this->assertEquals($expectedCount, $document->files()->count());
    }

    /** @test */
    public function it_updates_fts_index_after_replacement(): void
    {
        $document = $this->createDocumentWithAsiceFiles();

        $pdfChild = $document->files()->whereNotNull('parent_id')->first();

        $pdfChild->replaceWith($this->tempCopy(base_path('tests/__fixtures/pii_ex/julg.pdf')));

        // Refresh document to get updated file_contents
        $document->refresh();

        // The new file's name should appear in the document's file_contents
        $this->assertStringContainsString('julg.pdf', $document->file_contents);
        // The old file's name should no longer appear
        $this->assertStringNotContainsString('50k.pdf', $document->file_contents);
    }

    /** @test */
    public function replace_file_endpoint_requires_admin(): void
    {
        $document = $this->createDocumentWithAsiceFiles();
        $file = $document->files()->first();

        $response = $this->post(route('file.replace', $file), [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 100),
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function replace_file_endpoint_replaces_file_for_admin(): void
    {
        $document = $this->createDocumentWithAsiceFiles();

        $asiceFile = $document->files()->whereNull('parent_id')->first();
        $pdfChild = $document->files()->where('parent_id', $asiceFile->id)->first();

        $this->assertNotNull($pdfChild);
        $oldPdfId = $pdfChild->id;

        $tmpPath = $this->tempCopy(base_path('tests/__fixtures/pii_ex/julg.pdf'));
        $uploadedFile = new UploadedFile($tmpPath, 'julg.pdf', 'application/pdf', null, true);

        $response = $this->withSession(['is_admin' => true])
            ->post(route('file.replace', $pdfChild), [
                'file' => $uploadedFile,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'File replaced successfully');

        // Old file should be gone
        $this->assertNull(File::find($oldPdfId));

        // New file should exist with same parent and document
        $document->refresh();
        $newChild = $document->files()->where('parent_id', $asiceFile->id)->first();
        $this->assertNotNull($newChild);
        $this->assertEquals('julg.pdf', $newChild->name);
        $this->assertEquals($document->id, $newChild->document_id);

        // FTS should be updated
        $this->assertStringContainsString('julg.pdf', $document->file_contents);
        $this->assertStringNotContainsString('50k.pdf', $document->file_contents);
    }

    /** @test */
    public function replace_file_endpoint_requires_file_upload(): void
    {
        $document = $this->createDocumentWithAsiceFiles();
        $file = $document->files()->first();

        $response = $this->withSession(['is_admin' => true])
            ->post(route('file.replace', $file), []);

        $response->assertSessionHasErrors('file');
    }
}

