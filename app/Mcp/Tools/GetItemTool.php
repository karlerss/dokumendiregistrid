<?php

namespace App\Mcp\Tools;

use App\Models\DocumentStatusChange;
use App\Models\File;
use App\Models\PiiSubject;
use App\Models\ReviewItem;
use App\Models\TakedownRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_item')]
#[Description('Everything needed to decide one review item: all flags, the document, what its source registry says now, status-change history, files with public URLs, takedown requests, PII assessment and subjects, and the log of earlier decisions.')]
#[IsReadOnly]
class GetItemTool extends ComplianceTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['item_id' => 'required|integer']);
        $item = ReviewItem::with('document.organisation', 'document.remoteState', 'actions')->find($data['item_id']);

        if (!$item) {
            return Response::error("No review item #{$data['item_id']}.");
        }

        $doc = $item->document;
        $state = $doc?->remoteState;

        return Response::structured($this->itemSummary($item) + [
            'flags' => $item->flags,
            'document' => $this->documentSummary($doc),
            'source_status' => $state ? [
                'status' => $state->remote_status,
                'restriction' => $state->remote_restriction,
                'basis' => $state->remote_restriction_basis,
                'personal_data' => (bool)$state->personal_data_restriction,
                'checked_at' => $state->checked_at?->toIso8601String(),
                'http' => $state->last_http_status,
            ] : null,
            'status_changes' => $doc ? DocumentStatusChange::where('document_id', $doc->id)->orderByDesc('occurred_at')->limit(10)->get()
                ->map(fn($c) => [
                    'id' => $c->id,
                    'occurred_at' => $c->occurred_at?->toIso8601String(),
                    'from' => $c->from_status,
                    'to' => $c->to_status,
                    'restriction' => $c->to_restriction,
                    'basis' => $c->basis,
                    'personal_data' => (bool)$c->personal_data,
                    'acknowledged_at' => $c->acknowledged_at?->toIso8601String(),
                    'action' => $c->action,
                ])->all() : [],
            'files' => $doc ? File::where('document_id', $doc->id)->orderBy('id')->get()->map(fn(File $f) => [
                'id' => $f->id,
                'parent_id' => $f->parent_id,
                'name' => $f->name,
                'public_url' => $f->url,
                'withheld' => (bool)$f->original_withheld,
                'redacted' => $f->redacted_location !== null,
            ])->all() : [],
            'takedowns' => $doc ? TakedownRequest::where('document_id', $doc->id)->orderBy('id')->get()->map(fn($t) => [
                'id' => $t->id,
                'status' => $t->status,
                'author_name' => $t->author_name,
                'legal_basis' => $t->legal_basis,
                'objection_note' => $t->objection_note,
                'verified_at' => $t->verified_at?->toIso8601String(),
                'resolved_at' => $t->resolved_at?->toIso8601String(),
            ])->all() : [],
            'pii' => $doc ? [
                'assessment' => ($a = $doc->latestPiiAssessment) ? [
                    'band' => $a->band,
                    'recommendation' => $a->recommendation,
                    'reviewed' => $a->review_action,
                ] : null,
                'subjects' => PiiSubject::where('document_id', $doc->id)->orderBy('id')->get()->map(fn($s) => [
                    'name' => $s->name,
                    'context' => $s->context_override ?? $s->context,
                    'role' => $s->role,
                    'organisation' => $s->organisation,
                    'confidence' => $s->confidence,
                ])->all(),
            ] : null,
            'log' => $item->actions->map(fn($a) => [
                'at' => $a->created_at?->toIso8601String(),
                'effect' => $a->effect,
                'actor' => $a->actor,
                'result' => $a->result,
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required(),
        ];
    }
}
