<?php

use App\Http\Middleware\EnsureMcpUser;
use App\Mcp\Servers\AdminServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (plan 13)
|--------------------------------------------------------------------------
|
| Loaded by laravel/mcp outside the "web" group: no session, cookies, CSRF
| or CORS (C2, C6). Static "posm_" bearer tokens only; no OAuth routes.
| Order (C30): per-IP limit → token → holder check → per-token limit.
|
*/

Mcp::web('/mcp/admin', AdminServer::class)
    ->middleware(['throttle:mcp-ip', 'mcp.auth', EnsureMcpUser::class, 'throttle:mcp'])
    ->name('mcp.admin');
