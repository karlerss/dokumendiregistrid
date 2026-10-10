<?php

namespace App\Mcp\Tools;

use App\Lib\Compliance\UrlResolver;
use App\Models\Document;
use App\Models\ReviewItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('resolve_urls')]
#[Description('Map URLs (site pages, adr-docs file URLs, source-registry URLs) to documents and files without flagging anything. Reports objects in the public bucket that no files row points at, and any review item the document already has.')]
#[IsReadOnly]
class ResolveUrlsTool extends ComplianceTool
{
    public function handle(Request $request, UrlResolver $resolver): Response|ResponseFactory
    {
        $data = $request->validate([
            'urls' => 'required|array|min:1|max:200',
            'urls.*' => 'string|max:2000',
        ]);

        $results = [];
        foreach ($data['urls'] as $url) {
            $r = $resolver->resolve($url);
            $doc = $r['document_id'] ? Document::with('organisation')->find($r['document_id']) : null;
            $item = $doc
                ? ReviewItem::where('document_id', $doc->id)->latest('id')->first()
                : ($r['file_key'] ? ReviewItem::whereNull('document_id')->where('file_key', $r['file_key'])->latest('id')->first() : null);
            $results[] = $r + [
                'document' => $this->documentSummary($doc),
                'review_item' => $item ? $this->itemSummary($item) : null,
            ];
        }

        return Response::structured(['results' => $results]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'urls' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
