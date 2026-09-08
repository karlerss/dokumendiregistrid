<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRemoteState;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchChangedVisibilityFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organisation::create(['name' => 'SOM', 'slug' => 'som', 'registry_base_uri' => 'https://adr.rik.ee/som/', 'fetcher_type' => 'delta-adr']);
    }

    private function doc(string $title, ?string $remoteStatus, string $restriction = 'Avalik'): Document
    {
        $doc = Document::create([
            'organisation_id' => $this->org->id,
            'url' => 'https://adr.rik.ee/som/dokument/' . uniqid(),
            'original_id' => '1',
            'title' => $title,
            'reference' => 'R',
            'registration_date' => '2024-01-01',
            'type' => 'Kiri',
            'restriction' => $restriction,
        ]);
        if ($remoteStatus !== null) {
            DocumentRemoteState::create(['document_id' => $doc->id, 'remote_status' => $remoteStatus, 'remote_restriction_basis' => $remoteStatus === 'restricted' ? 'AvTS § 35 lg 1 p 12' : null]);
        }
        return $doc;
    }

    public function test_filter_shows_only_documents_restricted_or_removed_at_the_source(): void
    {
        $this->doc('Still public', 'public');
        $this->doc('Never checked', null);
        $this->doc('Now restricted', 'restricted');
        $this->doc('Now gone', 'gone');

        $response = $this->get('/?changed_visibility=1');

        $response->assertOk()
            ->assertSee('Now restricted')
            ->assertSee('Now gone')
            ->assertDontSee('Still public')
            ->assertDontSee('Never checked')
            ->assertSee('⚠️')
            ->assertSee('AvTS § 35 lg 1 p 12');
        $this->assertSame(2, $response->viewData('documents')->total());
    }

    public function test_filter_combines_with_search_and_restricted_toggle(): void
    {
        $this->doc('Leping A', 'restricted');
        $this->doc('Leping B', 'public');
        $this->doc('Leping C', 'gone', 'AK');
        Document::ftsIndexAll();

        $onlyPublicAtIngest = $this->get('/?query=Leping&changed_visibility=1');
        $onlyPublicAtIngest->assertOk()->assertSee('Leping A')->assertDontSee('Leping B')->assertDontSee('Leping C');

        $withAk = $this->get('/?query=Leping&changed_visibility=1&with_restricted=1');
        $withAk->assertOk()->assertSee('Leping A')->assertSee('Leping C')->assertDontSee('Leping B');
    }

    public function test_without_the_filter_everything_public_is_listed(): void
    {
        $this->doc('Still public', 'public');
        $this->doc('Now restricted', 'restricted');

        $this->get('/')->assertOk()->assertSee('Still public')->assertSee('Now restricted');
    }
}
