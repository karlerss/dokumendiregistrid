<?php

namespace App\Mcp\Tools;

use App\Models\ReviewItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_queue')]
#[Description('List review items, most urgent first (priority 1 = personal-data restriction, letter or takedown; 2 = other restriction; 3 = PII high; 4 = gone at source). Defaults to open items. Filter personal_data=true for "isikuandmed" cases, and public_sector=true/false to see items requested by public bodies or by private persons and companies.')]
#[IsReadOnly]
class ListQueueTool extends ComplianceTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'status' => 'nullable|in:open,decided',
            'source' => 'nullable|string|max:32',
            'reference' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:100',
            'offset' => 'nullable|integer|min:0',
            'personal_data' => 'nullable|boolean',
            'public_sector' => 'nullable|boolean',
        ]);

        $personalData = "(exists (select 1 from json_each(review_items.flags) where json_extract(value, '$.personal_data') = 1)"
            . " or exists (select 1 from document_remote_states s where s.document_id = review_items.document_id and s.personal_data_restriction = 1))";

        $query = ReviewItem::query()
            ->where('status', $data['status'] ?? ReviewItem::STATUS_OPEN)
            ->when($data['source'] ?? null, fn($q, $s) => $q->whereRaw("exists (select 1 from json_each(review_items.flags) where json_extract(value, '$.source') = ?)", [$s]))
            ->when($data['reference'] ?? null, fn($q, $r) => $q->whereRaw("exists (select 1 from json_each(review_items.flags) where json_extract(value, '$.reference') = ?)", [$r]))
            ->when(isset($data['personal_data']), fn($q) => $q->whereRaw($data['personal_data'] ? $personalData : "not $personalData"))
            // A flag records whether its requester is a public body; flags
            // without a requester (the daemon) match neither value.
            ->when(isset($data['public_sector']), fn($q) => $q->whereRaw(
                "exists (select 1 from json_each(review_items.flags) where json_extract(value, '$.public_sector') = ?)",
                [$data['public_sector'] ? 1 : 0]
            ));

        $total = (clone $query)->count();
        $items = $query->with('document.organisation', 'document.remoteState')
            ->orderBy('priority')->orderBy('first_flagged_at')->orderBy('id')
            ->offset($data['offset'] ?? 0)->limit($data['limit'] ?? 50)
            ->get();

        return Response::structured([
            'total' => $total,
            'oldest_open_flagged_at' => ReviewItem::open()->min('first_flagged_at'),
            'items' => $items->map(fn(ReviewItem $i) => $this->itemSummary($i) + [
                'document' => $this->documentSummary($i->document),
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['open', 'decided'])->description('Default: open.'),
            'source' => $schema->string()->description('letter, recheck, takedown or pii.'),
            'reference' => $schema->string()->description('Only items flagged with this exact reference.'),
            'limit' => $schema->integer()->description('Max 100, default 50.'),
            'offset' => $schema->integer(),
            'personal_data' => $schema->boolean()->description('true: only "isikuandmed" cases (personal-data flag or the source restricts it under AvTS § 35 lg 1 p 12); false: only the others.'),
            'public_sector' => $schema->boolean()->description('true: requested by a public-sector body (AKI, agencies); false: requested by a private person or company. Daemon-only items match neither.'),
        ];
    }
}
