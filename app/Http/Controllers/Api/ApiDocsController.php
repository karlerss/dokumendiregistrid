<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ApiDocsController extends Controller
{
    public function ui()
    {
        return view('api.docs');
    }

    public function spec(): JsonResponse
    {
        return response()->json($this->buildSpec(), 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function buildSpec(): array
    {
        $documentSchema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'ai_title' => ['type' => 'string', 'nullable' => true],
                'reference' => ['type' => 'string'],
                'registration_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'type' => ['type' => 'string'],
                'function' => ['type' => 'string', 'nullable' => true],
                'series' => ['type' => 'string', 'nullable' => true],
                'dossier' => ['type' => 'string', 'nullable' => true],
                'restriction' => ['type' => 'string', 'example' => 'Avalik'],
                'to' => ['type' => 'string', 'nullable' => true],
                'method' => ['type' => 'string', 'nullable' => true],
                'responsible' => ['type' => 'string', 'nullable' => true],
                'url' => ['type' => 'string', 'nullable' => true],
                'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'organisation' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $documentDetailSchema = [
            'allOf' => [
                ['$ref' => '#/components/schemas/Document'],
                [
                    'type' => 'object',
                    'properties' => [
                        'ai_summary' => ['type' => 'string', 'nullable' => true],
                        'files' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/File'],
                        ],
                    ],
                ],
            ],
        ];

        $fileSchema = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'parent_id' => ['type' => 'integer', 'nullable' => true],
                'parsed_with' => ['type' => 'string', 'nullable' => true],
                'url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true, 'description' => 'Public URL of the hosted file (object storage).'],
                'contents' => ['type' => 'string', 'nullable' => true, 'description' => 'Extracted text content of the file.'],
            ],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Document Registries API',
                'version' => '1.0.0',
                'description' => "Read-only API over aggregated Estonian public-sector document registries.\n\n"
                    . "**Rate limit:** 10 requests per 10 seconds per IP address. Exceeding the limit returns HTTP 429.\n\n"
                    . "**User-Agent required:** All requests must include a descriptive `User-Agent` header that identifies your application and provides a contact (e.g. `MyApp/1.0 (you@example.com)`). Requests without a `User-Agent` header are rejected with HTTP 400.",
            ],
            'servers' => [
                ['url' => url('/api'), 'description' => 'This server'],
            ],
            'paths' => [
                '/documents' => [
                    'get' => [
                        'summary' => 'Search documents',
                        'description' => 'Full-text search and filtering over documents. Uses SQLite FTS5 syntax for the `query` parameter (wrap exact phrases in double quotes).',
                        'parameters' => [
                            ['name' => 'query', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'FTS5 search expression. Searches title, responsible, series, "to", function, original_id, reference and file contents.'],
                            ['name' => 'org_ids', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Comma-separated list of organisation IDs to filter by.'],
                            ['name' => 'with_restricted', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1], 'default' => 0], 'description' => 'When `1`, include access-restricted (AK) documents. Default is `0` (only public/"Avalik").'],
                            ['name' => 'date_start', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Earliest registration date (inclusive).'],
                            ['name' => 'date_end', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Latest registration date (inclusive).'],
                            ['name' => 'sort_by', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['registration_date', 'created_at'], 'default' => 'registration_date']],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Paginated list of documents.',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Document']],
                                        'current_page' => ['type' => 'integer'],
                                        'per_page' => ['type' => 'integer'],
                                        'total' => ['type' => 'integer'],
                                        'last_page' => ['type' => 'integer'],
                                    ],
                                ]]],
                            ],
                            '400' => ['description' => 'Missing User-Agent header.'],
                            '422' => ['description' => 'Invalid search query.'],
                            '429' => ['description' => 'Rate limit exceeded (10 requests / 10s per IP).'],
                        ],
                    ],
                ],
                '/documents/{document}' => [
                    'get' => [
                        'summary' => 'Get a single document with its files (including extracted text contents).',
                        'parameters' => [
                            ['name' => 'document', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                        ],
                        'responses' => [
                            '200' => ['description' => 'Document detail.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DocumentDetail']]]],
                            '400' => ['description' => 'Missing User-Agent header.'],
                            '404' => ['description' => 'Document not found.'],
                            '429' => ['description' => 'Rate limit exceeded (10 requests / 10s per IP).'],
                            '451' => ['description' => 'Document is access-restricted.'],
                        ],
                    ],
                ],
                '/organisations' => [
                    'get' => [
                        'summary' => 'List organisations',
                        'description' => 'Returns all organisations (id, name, slug). Use the returned ids in the `org_ids` query parameter of `/documents`.',
                        'responses' => [
                            '200' => [
                                'description' => 'List of organisations.',
                                'content' => ['application/json' => ['schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Organisation']],
                                    ],
                                ]]],
                            ],
                            '400' => ['description' => 'Missing User-Agent header.'],
                            '429' => ['description' => 'Rate limit exceeded (10 requests / 10s per IP).'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Document' => $documentSchema,
                    'DocumentDetail' => $documentDetailSchema,
                    'File' => $fileSchema,
                    'Organisation' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
