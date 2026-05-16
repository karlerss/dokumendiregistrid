<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class DocumentApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'query' => 'nullable|string|max:500',
            'org_ids' => 'nullable|string',
            'with_restricted' => 'nullable|in:0,1',
            'date_start' => 'nullable|date',
            'date_end' => 'nullable|date',
            'sort_by' => 'nullable|in:registration_date,created_at',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($search = $request->get('query')) {
            $query = DB::table('fts_documents')
                ->join('documents', 'fts_documents.rowid', '=', 'documents.id')
                ->whereFullText('fts_documents', $search);
        } else {
            $query = DB::table('documents');
        }

        if ($request->org_ids) {
            $query->whereIn('organisation_id', explode(',', $request->org_ids));
        }

        if ($request->with_restricted != 1) {
            $query->where('restriction', 'Avalik');
        }

        if ($start = $request->date_start) {
            $query->where('registration_date', '>=', $start);
        }

        if ($end = $request->date_end) {
            $query->where('registration_date', '<=', $end);
        }

        try {
            $count = $query->clone()->selectRaw('count(*) as count')->get()[0]->count;

            if ($request->sort_by === 'created_at') {
                $query->orderBy('created_at', 'desc');
            } else {
                $query->orderBy('registration_date', 'desc')
                    ->orderBy('documents.original_id', 'desc');
            }

            $page = (int)($request->page ?? 1);
            $perPage = 100;

            $ids = $query->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->orderBy('rank')
                ->pluck('id');

            $items = Document::query()->whereIn('id', $ids)
                ->with(['organisation'])
                ->get()
                ->keyBy('id')
                ->all();

            $items = array_values(array_replace(array_flip($ids->all()), $items));
            $items = array_values(array_filter($items, fn ($i) => $i instanceof Document));

            $paginator = new LengthAwarePaginator(
                collect($items)->map(fn (Document $d) => $this->transformListItem($d)),
                $count,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'fts5') || str_contains($e->getMessage(), 'column')) {
                return response()->json([
                    'error' => 'Invalid search query. The "query" parameter uses SQLite FTS5 syntax. Wrap exact phrases in double quotes.',
                ], 422);
            }
            throw $e;
        }

        return response()->json($paginator->toArray());
    }

    public function show(Document $document): JsonResponse
    {
        if ($document->last_visibility === 'AK' || $document->last_visibility === 'Unknown') {
            abort(451, 'This document is access-restricted.');
        }

        $document->load(['organisation', 'files']);

        return response()->json($this->transformDetail($document));
    }

    public function organisations(): JsonResponse
    {
        $orgs = Organisation::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Organisation $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
            ]);

        return response()->json(['data' => $orgs]);
    }

    private function transformListItem(Document $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'ai_title' => $d->ai_title,
            'reference' => $d->reference,
            'registration_date' => optional($d->registration_date)->toDateString(),
            'type' => $d->type,
            'function' => $d->function,
            'series' => $d->series,
            'dossier' => $d->dossier,
            'restriction' => $d->restriction,
            'to' => $d->to,
            'method' => $d->method,
            'responsible' => $d->responsible,
            'url' => $d->url,
            'created_at' => optional($d->created_at)->toIso8601String(),
            'organisation' => $d->organisation ? [
                'id' => $d->organisation->id,
                'name' => $d->organisation->name,
                'slug' => $d->organisation->slug,
            ] : null,
        ];
    }

    private function transformDetail(Document $d): array
    {
        return array_merge($this->transformListItem($d), [
            'ai_summary' => $d->ai_summary,
            'files' => $d->files->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'parent_id' => $f->parent_id,
                'parsed_with' => $f->parsed_with,
                'url' => $f->url,
                'contents' => $f->contents,
            ])->values(),
        ]);
    }
}
