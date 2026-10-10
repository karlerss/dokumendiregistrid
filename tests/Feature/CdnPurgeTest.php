<?php

namespace Tests\Feature;

use App\Lib\CdnPurge;
use App\Models\Document;
use App\Models\File;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CdnPurgeTest extends TestCase
{
    use RefreshDatabase;

    private File $file;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2');
        Storage::fake('r2_private');
        $org = Organisation::create(['name' => 'RMK', 'slug' => 'rmk', 'registry_base_uri' => 'https://adr.rmk.ee/', 'fetcher_type' => 'rmk']);
        $doc = Document::create(['organisation_id' => $org->id, 'url' => 'https://adr.rmk.ee/dokument/1', 'original_id' => '1', 'title' => 'T', 'reference' => 'R', 'registration_date' => '2026-01-01', 'type' => 'Kiri', 'restriction' => 'Avalik']);
        Storage::disk('r2')->put('Abc/V. Tamm teenistusleht.pdf', 'x');
        Storage::disk('r2')->put('Red/koopia.pdf', 'y');
        $this->file = File::create(['document_id' => $doc->id, 'name' => 'a.pdf', 'location' => 'Abc/V. Tamm teenistusleht.pdf', 'redacted_location' => 'Red/koopia.pdf']);
    }

    public function test_deleting_a_file_purges_its_public_urls(): void
    {
        config(['services.cloudflare.zone_id' => 'zone1', 'services.cloudflare.api_token' => 'tok']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->file->delete();

        Http::assertSent(fn($r) => $r->url() === 'https://api.cloudflare.com/client/v4/zones/zone1/purge_cache'
            && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['files'] === [
                'https://adr-docs.karlerss.com/Abc/V.%20Tamm%20teenistusleht.pdf',
                'https://adr-docs.karlerss.com/Red/koopia.pdf',
            ]);
    }

    public function test_without_configuration_nothing_is_sent_and_delete_still_works(): void
    {
        config(['services.cloudflare.zone_id' => null, 'services.cloudflare.api_token' => null]);
        Http::fake();

        $this->file->delete();

        Http::assertNothingSent();
        Storage::disk('r2')->assertMissing('Abc/V. Tamm teenistusleht.pdf');
    }

    public function test_a_failed_purge_is_reported_not_thrown(): void
    {
        config(['services.cloudflare.zone_id' => 'zone1', 'services.cloudflare.api_token' => 'tok']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 403)]);

        $result = app(CdnPurge::class)->keys(['Abc/x.pdf']);

        $this->assertSame(['https://adr-docs.karlerss.com/Abc/x.pdf'], $result['failed']);
        $this->assertSame([], $result['purged']);
    }

    public function test_urls_are_sent_in_batches_of_thirty(): void
    {
        config(['services.cloudflare.zone_id' => 'zone1', 'services.cloudflare.api_token' => 'tok']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);

        $result = app(CdnPurge::class)->keys(array_map(fn($i) => "K/$i.pdf", range(1, 65)));

        $this->assertCount(65, $result['purged']);
        Http::assertSentCount(3);
    }
}
