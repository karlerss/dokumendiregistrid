<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CheckExposureTool;
use App\Mcp\Tools\DecideTool;
use App\Mcp\Tools\FlagTool;
use App\Mcp\Tools\GetItemTool;
use App\Mcp\Tools\ListQueueTool;
use App\Mcp\Tools\ResolveUrlsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('dokumendiregistrid compliance')]
#[Version('0.1.0')]
#[Instructions(<<<'TXT'
Review queue for dokumendiregistrid.karlerss.com. Documents get flagged (letters from AKI or agencies via `flag`; later also the re-check daemon, takedown requests and PII assessments) and every flagged document waits as one review item until someone decides: remove, redact or keep.

Workflow: `flag` the URLs a letter names (with its reference) -> `list_queue` -> `get_item` and `check_exposure` -> `decide`. Nothing is ever removed without an explicit decision on a single item; `remove` needs a dry run and then confirm=true with the returned plan_id. Ask the human before confirming a removal unless they have told you otherwise. Afterwards, run `check_exposure` again for proof.
TXT)]
class ComplianceServer extends Server
{
    protected array $tools = [
        ListQueueTool::class,
        GetItemTool::class,
        FlagTool::class,
        ResolveUrlsTool::class,
        CheckExposureTool::class,
        DecideTool::class,
    ];
}
