<?php

use App\Http\Middleware\RequireAdminToken;
use App\Mcp\Servers\ComplianceServer;
use Laravel\Mcp\Facades\Mcp;

// Compliance review queue for agents. Every tool, reads included, needs
// `Authorization: Bearer <ADMIN_TOKEN>`.
// (No IP-keyed throttle: behind Cloudflare request()->ip() is a shared edge.
// RequireAdminToken does the per-client lockout using the real address.)
Mcp::web('/mcp', ComplianceServer::class)
    ->middleware([RequireAdminToken::class]);
