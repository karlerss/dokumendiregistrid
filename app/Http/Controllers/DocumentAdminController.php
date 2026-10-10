<?php

namespace App\Http\Controllers;

use App\Lib\Compliance\AdminActions;
use App\Lib\Compliance\DecisionException;
use App\Models\Document;
use App\Models\ReviewItem;
use Illuminate\Http\Request;

/**
 * The decision form in the admin panel of the document page: remove, redact
 * or keep, with a reason (and, for redact, optional strings to remove
 * instead of the PII assessment's plan).
 */
class DocumentAdminController extends Controller
{
    public function decide(Request $request, Document $document, AdminActions $admin)
    {
        if (!session('is_admin')) {
            abort(403);
        }

        $data = $request->validate([
            'decision' => 'required|in:remove,redact,keep',
            'reason' => 'required|string|max:5000',
            'reference' => 'nullable|string|max:255',
            'strings' => 'nullable|string|max:20000',
        ]);
        $strings = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($data['strings'] ?? '')))));

        try {
            $admin->decide(
                $document,
                $data['decision'],
                $data['reason'],
                ReviewItem::SOURCE_ADMIN,
                ReviewItem::PRIORITY_NAMED,
                $data['reference'] ?? null,
                'dokumendi leht',
                [],
                $data['decision'] === ReviewItem::DECISION_REDACT && $strings ? ['strings' => $strings] : [],
            );
        } catch (DecisionException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', match ($data['decision']) {
            ReviewItem::DECISION_REMOVE => 'Dokument eemaldati: failid, tekst ja isikuandmete read kustutati.',
            ReviewItem::DECISION_REDACT => 'Redigeerimine on järjekorda pandud. Tekst asendatakse esimesena, failid seejärel.',
            default => 'Otsus salvestati: dokument jääb alles.',
        });
    }
}
