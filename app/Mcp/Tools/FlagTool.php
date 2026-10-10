<?php

namespace App\Mcp\Tools;

use App\Lib\Compliance\ReviewQueue;
use App\Lib\Compliance\UrlResolver;
use App\Models\Document;
use App\Models\ReviewItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('flag')]
#[Description('Enter documents named in a letter (AKI, an agency, a data subject) into the review queue. Accepts site page URLs, adr-docs file URLs, source-registry URLs and document ids. Creates or updates one open review item per document at top priority; never changes what is published. Re-entering the same letter is a no-op.')]
#[IsIdempotent]
class FlagTool extends ComplianceTool
{
    public function handle(Request $request, ReviewQueue $queue, UrlResolver $resolver): Response|ResponseFactory
    {
        $data = $request->validate([
            'urls' => 'array|max:200',
            'urls.*' => 'string|max:2000',
            'document_ids' => 'array|max:200',
            'document_ids.*' => 'integer',
            'reference' => 'required|string|max:255',
            'note' => 'nullable|string|max:2000',
            'public_sector' => 'required|boolean',
            'personal_data' => 'required|boolean',
        ]);
        $refs = ['public_sector' => (bool)$data['public_sector'], 'personal_data' => (bool)$data['personal_data']];

        $results = [];
        foreach ($data['urls'] ?? [] as $url) {
            $resolved = $resolver->resolve($url);
            $document = $resolved['document_id'] ? Document::find($resolved['document_id']) : null;

            if ($document) {
                $item = $queue->flag($document, $resolved['file_key'], ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, $data['reference'], $data['note'] ?? $url, $refs);
            } elseif ($resolved['kind'] === UrlResolver::KIND_ORPHAN_OBJECT) {
                $item = $queue->flag(null, $resolved['file_key'], ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, $data['reference'], $data['note'] ?? $url, $refs);
            } else {
                $results[] = ['input' => $url, 'kind' => $resolved['kind'], 'item_id' => null, 'note' => $resolved['note']];
                continue;
            }
            $results[] = $this->row($url, $resolved['kind'], $item, $document);
        }

        foreach ($data['document_ids'] ?? [] as $id) {
            $document = Document::find($id);
            if (!$document) {
                $results[] = ['input' => $id, 'kind' => 'unknown', 'item_id' => null, 'note' => 'No such document.'];
                continue;
            }
            $item = $queue->flag($document, null, ReviewItem::SOURCE_LETTER, ReviewItem::PRIORITY_NAMED, $data['reference'], $data['note'] ?? null, $refs);
            $results[] = $this->row($id, UrlResolver::KIND_DOCUMENT, $item, $document);
        }

        return Response::structured([
            'reference' => $data['reference'],
            'flagged' => count(array_filter($results, fn($r) => $r['item_id'] !== null)),
            'unresolved' => count(array_filter($results, fn($r) => $r['item_id'] === null)),
            'results' => $results,
        ]);
    }

    private function row(string|int $input, string $kind, ReviewItem $item, ?Document $document): array
    {
        return [
            'input' => $input,
            'kind' => $kind,
            'item_id' => $item->id,
            'item_status' => $item->status,
            'document_id' => $document?->id,
            'note' => $document?->isRemoved() ? 'Document was already removed; nothing to review.' : null,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'urls' => $schema->array()->items($schema->string())->description('URLs exactly as quoted in the letter.'),
            'document_ids' => $schema->array()->items($schema->integer())->description('Document ids, when known.'),
            'reference' => $schema->string()->required()->description('Letter or case reference, e.g. "AKI 2.1-1/26/123-4 01.06.2026".'),
            'note' => $schema->string()->description('Optional note stored on each flag; defaults to the URL.'),
            'public_sector' => $schema->boolean()->required()->description('true when the letter comes from a public-sector body (AKI, an agency, a ministry); false for a private person or company.'),
            'personal_data' => $schema->boolean()->required()->description('true when the letter is about personal data (an "isikuandmed" matter: erasure request, objection, a restriction under AvTS § 35 lg 1 p 12).'),
        ];
    }
}
