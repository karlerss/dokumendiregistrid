<?php

namespace Tests\Feature;

use App\Mail\TakedownAcceptedMail;
use App\Mail\TakedownAcceptedRemovedMail;
use App\Mail\TakedownDeniedMail;
use App\Mail\TakedownVerificationMail;
use App\Models\Document;
use App\Models\Organisation;
use App\Models\TakedownRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TakedownTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(array $overrides = []): Document
    {
        $org = Organisation::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'registry_base_uri' => 'https://test.example.com/' . uniqid(),
        ]);

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

    private function storeData(array $overrides = []): array
    {
        return array_merge([
            'author_name' => 'Jaan Tamm',
            'author_email' => 'jaan@example.com',
            'legal_basis' => TakedownRequest::LEGAL_BASES[0],
            'objection_note' => 'Palun eemaldage.',
        ], $overrides);
    }

    /** @test */
    public function submitting_creates_an_unverified_request_with_token_and_sends_code(): void
    {
        Mail::fake();
        $document = $this->makeDocument();

        $response = $this->post(
            route('takedowns.store', $document),
            $this->storeData()
        );

        $request = TakedownRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(TakedownRequest::STATUS_UNVERIFIED, $request->status);
        $this->assertNotEmpty($request->token);
        $this->assertNotEmpty($request->verification_code);
        $this->assertNull($request->verified_at);

        $this->assertSame(
            route('document', ['document' => $document->id, 'slug' => \Illuminate\Support\Str::slug($document->title)]),
            $request->original_document_url
        );

        $response->assertRedirect(route('takedowns.track', $request));

        Mail::assertSent(TakedownVerificationMail::class, fn ($mail) => $mail->hasTo('jaan@example.com'));
    }

    /** @test */
    public function submitting_validates_input(): void
    {
        $document = $this->makeDocument();

        $response = $this->post(route('takedowns.store', $document), $this->storeData([
            'author_email' => 'not-an-email',
            'legal_basis' => 'invalid',
        ]));

        $response->assertSessionHasErrors(['author_email', 'legal_basis']);
        $this->assertSame(0, TakedownRequest::count());
    }

    /** @test */
    public function track_page_is_accessible_by_token(): void
    {
        $document = $this->makeDocument();
        $request = $document->takedownRequests()->create($this->storeData([
            'original_document_url' => $document->url,
            'status' => TakedownRequest::STATUS_UNVERIFIED,
        ]));

        $response = $this->get(route('takedowns.track', $request));

        $response->assertStatus(200);
        $response->assertSee($request->statusLabel());
    }

    /** @test */
    public function verifying_with_correct_code_sets_status_to_pending(): void
    {
        Mail::fake();
        $document = $this->makeDocument();
        $this->post(route('takedowns.store', $document), $this->storeData());

        $request = TakedownRequest::first();
        $code = $request->verification_code;

        $response = $this->post(route('takedowns.verify', $request), [
            'verification_code' => $code,
        ]);

        $response->assertRedirect(route('takedowns.track', $request));

        $request->refresh();
        $this->assertSame(TakedownRequest::STATUS_PENDING, $request->status);
        $this->assertNotNull($request->verified_at);
        $this->assertNull($request->verification_code);
    }

    /** @test */
    public function verifying_with_wrong_code_keeps_request_unverified(): void
    {
        $document = $this->makeDocument();
        $request = $document->takedownRequests()->create($this->storeData([
            'status' => TakedownRequest::STATUS_UNVERIFIED,
        ]));
        $request->forceFill([
            'verification_code' => '123456',
            'verification_code_expires_at' => now()->addMinutes(30),
        ])->save();

        $response = $this->post(route('takedowns.verify', $request), [
            'verification_code' => '654321',
        ]);

        $response->assertSessionHasErrors('verification_code');

        $request->refresh();
        $this->assertSame(TakedownRequest::STATUS_UNVERIFIED, $request->status);
        $this->assertNull($request->verified_at);
    }

    /** @test */
    public function expired_code_is_rejected(): void
    {
        $document = $this->makeDocument();
        $request = $document->takedownRequests()->create($this->storeData([
            'status' => TakedownRequest::STATUS_UNVERIFIED,
        ]));
        $request->forceFill([
            'verification_code' => '123456',
            'verification_code_expires_at' => now()->subMinute(),
        ])->save();

        $this->post(route('takedowns.verify', $request), [
            'verification_code' => '123456',
        ])->assertSessionHasErrors('verification_code');

        $this->assertSame(TakedownRequest::STATUS_UNVERIFIED, $request->fresh()->status);
    }

    /** @test */
    public function resend_generates_a_new_code_and_sends_mail(): void
    {
        Mail::fake();
        $document = $this->makeDocument();
        $request = $document->takedownRequests()->create($this->storeData([
            'status' => TakedownRequest::STATUS_UNVERIFIED,
        ]));

        $this->post(route('takedowns.resend', $request))
            ->assertRedirect(route('takedowns.track', $request));

        $this->assertNotEmpty($request->fresh()->verification_code);
        Mail::assertSent(TakedownVerificationMail::class);
    }

    /** @test */
    public function resend_is_rate_limited_to_once_per_minute(): void
    {
        Mail::fake();
        $document = $this->makeDocument();
        $this->post(route('takedowns.store', $document), $this->storeData());

        $request = TakedownRequest::first();

        // The initial submit already sent a code, so an immediate resend is throttled.
        $this->post(route('takedowns.resend', $request))
            ->assertRedirect(route('takedowns.track', $request))
            ->assertSessionHas('error');

        Mail::assertSent(TakedownVerificationMail::class, 1);
    }

    /** @test */
    public function admin_index_requires_admin(): void
    {
        $this->get(route('takedowns.index'))->assertStatus(403);

        $this->withSession(['is_admin' => true])
            ->get(route('takedowns.index'))
            ->assertStatus(200);
    }

    /** @test */
    public function admin_can_accept_request_with_note(): void
    {
        Mail::fake();
        $document = $this->makeDocument();
        $request = $document->takedownRequests()->create($this->storeData([
            'status' => TakedownRequest::STATUS_PENDING,
        ]));

        $this->withSession(['is_admin' => true])
            ->post(route('takedowns.accept', $request), [
                'resolution_note' => 'Rahuldatud.',
            ])
            ->assertRedirect(route('takedowns.index'));

        $request->refresh();
        $this->assertSame(TakedownRequest::STATUS_ACCEPTED, $request->status);
        $this->assertNotNull($request->resolved_at);
        Mail::assertSent(TakedownAcceptedMail::class);
    }

    /** @test */
    public function admin_can_accept_and_remove_document(): void
    {
        Mail::fake();
        $document = $this->makeDocument();
        $request = $document->takedownRequests()->create($this->storeData([
            'status' => TakedownRequest::STATUS_PENDING,
        ]));

        $this->withSession(['is_admin' => true])
            ->post(route('takedowns.accept', $request), [
                'remove_document' => '1',
            ])
            ->assertRedirect(route('takedowns.index'));

        $request->refresh();
        $this->assertSame(TakedownRequest::STATUS_ACCEPTED, $request->status);
        $this->assertNull($request->document_id);
        $this->assertNull(Document::find($document->id));
        Mail::assertSent(TakedownAcceptedRemovedMail::class);
    }

    /** @test */
    public function admin_can_deny_request(): void
    {
        Mail::fake();
        $document = $this->makeDocument();
        $request = $document->takedownRequests()->create($this->storeData([
            'status' => TakedownRequest::STATUS_PENDING,
        ]));

        $this->withSession(['is_admin' => true])
            ->post(route('takedowns.deny', $request), [
                'resolution_note' => 'Põhjendamata.',
            ])
            ->assertRedirect(route('takedowns.index'));

        $request->refresh();
        $this->assertSame(TakedownRequest::STATUS_DENIED, $request->status);
        Mail::assertSent(TakedownDeniedMail::class);
    }
}
