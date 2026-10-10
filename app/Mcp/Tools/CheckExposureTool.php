<?php

namespace App\Mcp\Tools;

use App\Lib\Compliance\ExposureChecker;
use App\Lib\Compliance\UrlResolver;
use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('check_exposure')]
#[Description('Probe what the public can reach right now: HTTP status of each document page and every public R2 object, and whether the document is listed in search, the API and the sitemap. Accepts document ids and/or URLs (max 50 in total). Use before deciding and as proof afterwards.')]
#[IsReadOnly]
#[IsOpenWorld]
class CheckExposureTool extends ComplianceTool
{
    public function handle(Request $request, ExposureChecker $checker, UrlResolver $resolver): Response|ResponseFactory
    {
        $data = $request->validate([
            'document_ids' => 'array',
            'document_ids.*' => 'integer',
            'urls' => 'array',
            'urls.*' => 'string|max:2000',
        ]);

        $ids = $data['document_ids'] ?? [];
        $keys = [];
        foreach ($data['urls'] ?? [] as $url) {
            $r = $resolver->resolve($url);
            if ($r['document_id']) {
                $ids[] = $r['document_id'];
            } elseif ($r['file_key']) {
                $keys[] = $r['file_key'];
            }
        }
        $ids = array_values(array_unique($ids));
        $keys = array_values(array_unique($keys));

        if (count($ids) + count($keys) > 50) {
            return Response::error('At most 50 documents and objects per call.');
        }

        $documents = $checker->documents(Document::whereIn('id', $ids)->orderBy('id')->get());
        $objects = $checker->objects($keys);
        $exposed = count(array_filter($documents, fn($d) => $d['exposed'])) + count(array_filter($objects, fn($o) => $o['exposed']));

        return Response::structured([
            'exposed' => $exposed,
            'documents' => $documents,
            'objects' => $objects,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_ids' => $schema->array()->items($schema->integer()),
            'urls' => $schema->array()->items($schema->string()),
        ];
    }
}
