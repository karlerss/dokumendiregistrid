<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private const UA = ['User-Agent' => 'TestSuite/1.0 (test@example.com)'];

    private function makeOrg(array $overrides = []): Organisation
    {
        return Organisation::create(array_merge([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'registry_base_uri' => 'https://test.example.com/' . uniqid(),
        ], $overrides));
    }

    private function makeDocument(Organisation $org, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'organisation_id' => $org->id,
            'url' => 'https://test.example.com/doc/' . uniqid(),
            'original_id' => (string) random_int(1, 999999),
            'title' => 'Sample Document',
            'reference' => 'REF-1',
            'registration_date' => '2024-01-01',
            'type' => 'Kiri',
            'restriction' => 'Avalik',
        ], $overrides));
    }

    private function makeFile(Document $document, array $overrides = []): File
    {
        $file = new File(array_merge([
            'name' => 'attachment.pdf',
            'location' => 'abc123/attachment.pdf',
            'parsed_with' => \App\Lib\Parser\PdfParser::class,
            'contents' => 'extracted text',
        ], $overrides));
        $file->document_id = $document->id;
        $file->save();
        return $file;
    }

    /** @test */
    public function it_rejects_requests_without_user_agent(): void
    {
        $response = $this->withHeaders(['User-Agent' => ''])
            ->getJson('/api/organisations');

        $response->assertStatus(400)
            ->assertJsonStructure(['error', 'message']);
    }

    /** @test */
    public function it_lists_organisations(): void
    {
        $org = $this->makeOrg(['name' => 'Alpha', 'slug' => 'alpha']);

        $response = $this->withHeaders(self::UA)->getJson('/api/organisations');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $org->id)
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.0.slug', 'alpha');
    }

    /** @test */
    public function it_returns_document_detail_with_files_including_hosted_url(): void
    {
        $org = $this->makeOrg();
        $document = $this->makeDocument($org, ['title' => 'Detail Doc']);
        $file = $this->makeFile($document, ['location' => 'r2key/myfile.pdf', 'name' => 'myfile.pdf']);

        $response = $this->withHeaders(self::UA)->getJson('/api/documents/' . $document->id);

        $response->assertStatus(200)
            ->assertJsonPath('id', $document->id)
            ->assertJsonPath('title', 'Detail Doc')
            ->assertJsonPath('organisation.id', $org->id)
            ->assertJsonPath('files.0.id', $file->id)
            ->assertJsonPath('files.0.name', 'myfile.pdf')
            ->assertJsonPath('files.0.contents', 'extracted text')
            ->assertJsonStructure(['files' => [['id', 'name', 'parent_id', 'parsed_with', 'url', 'contents']]]);

        $this->assertStringContainsString('r2key/myfile.pdf', $response->json('files.0.url'));
    }

    /** @test */
    public function it_returns_404_for_unknown_document(): void
    {
        $response = $this->withHeaders(self::UA)->getJson('/api/documents/999999');

        $response->assertStatus(404);
    }

    /** @test */
    public function it_returns_451_for_restricted_document(): void
    {
        $org = $this->makeOrg();
        $document = $this->makeDocument($org, ['restriction' => 'AK']);
        $document->visible = false;
        $document->save();

        $response = $this->withHeaders(self::UA)->getJson('/api/documents/' . $document->id);

        $response->assertStatus(451);
    }

    /** @test */
    public function it_searches_documents_via_query(): void
    {
        $org = $this->makeOrg();
        $match = $this->makeDocument($org, ['title' => 'Uniquetitlexyz Doc', 'original_id' => '1001']);
        $this->makeDocument($org, ['title' => 'Other Document', 'original_id' => '1002']);

        $response = $this->withHeaders(self::UA)
            ->getJson('/api/documents?query=uniquetitlexyz');

        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonStructure(['data' => [['id', 'title', 'reference', 'organisation' => ['id', 'name', 'slug']]], 'current_page', 'per_page', 'total', 'last_page']);
    }

    private function remoteState(Document $document, string $status, bool $personalData = false): void
    {
        \App\Models\DocumentRemoteState::create([
            'document_id' => $document->id,
            'remote_status' => $status,
            'remote_restriction' => $status === 'restricted' ? 'AK' : null,
            'remote_restriction_basis' => $status === 'restricted' ? 'AvTS § 35 lg 1 p 12' : null,
            'personal_data_restriction' => $personalData,
            'checked_at' => '2026-09-20 10:00:00',
        ]);
    }

    /** @test */
    public function it_filters_documents_whose_source_visibility_changed(): void
    {
        $org = $this->makeOrg();
        $stillPublic = $this->makeDocument($org, ['title' => 'Still public']);
        $this->remoteState($stillPublic, 'public');
        $this->makeDocument($org, ['title' => 'Never checked']);
        $restricted = $this->makeDocument($org, ['title' => 'Now restricted']);
        $this->remoteState($restricted, 'restricted', personalData: true);
        $gone = $this->makeDocument($org, ['title' => 'Now gone']);
        $this->remoteState($gone, 'gone');

        $all = $this->withHeaders(self::UA)->getJson('/api/documents');
        $all->assertStatus(200)->assertJsonPath('total', 4);

        $changed = $this->withHeaders(self::UA)->getJson('/api/documents?changed_visibility=1');
        $changed->assertStatus(200)->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing([$restricted->id, $gone->id], collect($changed->json('data'))->pluck('id')->all());

        $personal = $this->withHeaders(self::UA)->getJson('/api/documents?personal_data=1');
        $personal->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $restricted->id)
            ->assertJsonPath('data.0.source_status.status', 'restricted')
            ->assertJsonPath('data.0.source_status.restriction', 'AK')
            ->assertJsonPath('data.0.source_status.restriction_basis', 'AvTS § 35 lg 1 p 12')
            ->assertJsonPath('data.0.source_status.personal_data', true)
            ->assertJsonPath('data.0.source_status.checked_at', '2026-09-20T10:00:00+00:00');

        $this->assertNull(collect($all->json('data'))->firstWhere('title', 'Never checked')['source_status']);

        $this->withHeaders(self::UA)->getJson('/api/documents?personal_data=2')->assertStatus(422);
    }

    /** @test */
    public function detail_includes_source_status(): void
    {
        $org = $this->makeOrg();
        $doc = $this->makeDocument($org);
        $this->remoteState($doc, 'gone');

        $this->withHeaders(self::UA)->getJson('/api/documents/' . $doc->id)
            ->assertStatus(200)
            ->assertJsonPath('source_status.status', 'gone')
            ->assertJsonPath('source_status.personal_data', false);
    }

    /** @test */
    public function openapi_spec_documents_the_source_status_filters(): void
    {
        $response = $this->withHeaders(self::UA)->getJson('/api/openapi.json');

        $response->assertStatus(200)
            ->assertJsonPath('components.schemas.Document.properties.source_status.type', 'object');
        $names = collect($response->json('paths./documents.get.parameters'))->pluck('name')->all();
        $this->assertContains('changed_visibility', $names);
        $this->assertContains('personal_data', $names);
    }

    /** @test */
    public function openapi_spec_includes_file_url_property(): void
    {
        $response = $this->withHeaders(self::UA)->getJson('/api/openapi.json');

        $response->assertStatus(200)
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('components.schemas.File.properties.url.type', 'string')
            ->assertJsonPath('components.schemas.File.properties.url.format', 'uri');
    }
}
