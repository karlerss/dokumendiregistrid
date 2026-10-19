<?php

namespace App\Mcp\Tools;

use App\Lib\Compliance\Decider;
use App\Lib\Compliance\DecisionException;
use App\Models\ReviewItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('decide')]
#[Description('Close one review item with remove, redact or keep. A reason is always required; for "keep" it is the case-specific legitimate-interest assessment. "remove" deletes every stored copy of the files plus the text (irreversible). "redact" replaces personal data in the text and metadata and replaces affected files with redacted copies (PDFs are redacted in place; .docx/.odt/.pptx converted to PDF and redacted; spreadsheets redacted cell by cell, then converted; .eml/.msg/.html rendered to a redacted PDF; images and signed containers withheld); it uses the PII assessment\'s plan, or `strings` if given, and can be reverted in /haldus/isikuandmed. Redacting a document that is already redacted replaces that redaction with one that removes both the earlier and the new strings; list only the new ones. For remove and redact, call first without confirm to get the plan and plan_id, then again with confirm=true and that plan_id. "keep" takes effect immediately and changes nothing public.')]
#[IsDestructive]
class DecideTool extends ComplianceTool
{
    public function handle(Request $request, Decider $decider): Response|ResponseFactory
    {
        $data = $request->validate([
            'item_id' => 'required|integer',
            'decision' => 'required|in:remove,redact,keep',
            'reason' => 'required|string|min:3|max:5000',
            'reference' => 'nullable|string|max:255',
            'confirm' => 'nullable|boolean',
            'plan_id' => 'nullable|string|max:64',
            'strings' => 'nullable|array|max:100',
            'strings.*' => 'string|min:2|max:300',
        ]);
        $options = array_filter(['strings' => $data['strings'] ?? null]);

        $item = ReviewItem::with('document')->find($data['item_id']);
        if (!$item) {
            return Response::error("No review item #{$data['item_id']}.");
        }

        try {
            if ($data['decision'] !== ReviewItem::DECISION_KEEP && empty($data['confirm'])) {
                $plan = $decider->plan($item, $data['decision'], $options);
                unset($plan['effects']['plan']);
                return Response::structured(['dry_run' => true] + $plan + [
                    'next' => 'To carry this out, call decide again with the same arguments plus confirm=true and this plan_id.',
                ]);
            }

            $item = $decider->decide(
                $item,
                $data['decision'],
                $data['reason'],
                $data['reference'] ?? null,
                $this->actor(),
                $data['plan_id'] ?? null,
                $options,
            );
        } catch (DecisionException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured(['dry_run' => false] + $this->itemSummary($item->fresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->integer()->required(),
            'decision' => $schema->string()->enum(['remove', 'redact', 'keep'])->required(),
            'reason' => $schema->string()->required()->description('Why. Stored on the item and in the audit log; for keep, the legitimate-interest assessment.'),
            'reference' => $schema->string()->description('Case or letter the decision answers, e.g. "AKI 2.1-1/26/123-4".'),
            'confirm' => $schema->boolean()->description('remove/redact: false/omitted = dry run returning plan_id.'),
            'plan_id' => $schema->string()->description('remove/redact: plan_id from the dry run.'),
            'strings' => $schema->array()->items($schema->string())->description('redact only: exact strings to remove (names, codes, addresses) instead of the PII assessment\'s plan. Names also match their Estonian case endings.'),
        ];
    }
}
