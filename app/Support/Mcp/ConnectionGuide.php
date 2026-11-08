<?php

namespace App\Support\Mcp;

use App\Livewire\Admin\Settings\McpAccess;

/**
 * Copy-paste snippets for Settings → MCP access (Plan 26). Pure builders: the
 * token is only ever embedded when the caller passes one (the show-once render);
 * otherwise the fixed placeholder is used.
 */
class ConnectionGuide
{
    public const PLACEHOLDER = 'PASTE_TOKEN_HERE';

    public const MAC_CONFIG_PATH = '~/Library/Application Support/Claude/claude_desktop_config.json';

    public const WIN_CONFIG_PATH = '%APPDATA%\\Claude\\claude_desktop_config.json';

    public const CHMOD_COMMAND = 'chmod 600 "$HOME/Library/Application Support/Claude/claude_desktop_config.json"';

    public const JSON_CHECK_COMMAND = 'node -e \'try{JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));console.log("OK")}catch{console.log("Invalid JSON")}\' "$HOME/Library/Application Support/Claude/claude_desktop_config.json"';

    public const LOG_CHECK_COMMAND = 'grep -l -E \'posm_|Bearer \' ~/Library/Logs/Claude/mcp*.log';

    /**
     * The "poson" entry of claude_desktop_config.json (token only in env, never in args).
     *
     * @return array{command: string, args: list<string>, env: array{POSON_MCP_AUTH: string}}
     */
    public static function serverEntry(string $endpoint, ?string $token = null): array
    {
        return [
            'command' => 'npx',
            'args' => ['-y', 'mcp-remote@'.McpAccess::MCP_REMOTE_VERSION, $endpoint, '--header', 'Authorization:${POSON_MCP_AUTH}'],
            'env' => ['POSON_MCP_AUTH' => 'Bearer '.($token ?? self::PLACEHOLDER)],
        ];
    }

    public static function config(string $endpoint, ?string $token = null): string
    {
        return json_encode(['mcpServers' => ['poson' => self::serverEntry($endpoint, $token)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Placeholder-only example of "poson" next to an existing server. */
    public static function mergeExample(string $endpoint): string
    {
        return json_encode(['mcpServers' => [
            'existing-server' => ['command' => '...'],
            'poson' => self::serverEntry($endpoint),
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Claude Code command: the token is read with `read -rs`, never part of the command. */
    public static function codeCommand(string $endpoint): string
    {
        return 'read -rs POSON_MCP_TOKEN && claude mcp add --transport http --scope user poson '.$endpoint.' --header "Authorization: Bearer $POSON_MCP_TOKEN"; unset POSON_MCP_TOKEN';
    }

    /** Read-only `initialize` check (M-1), macOS/Linux only. */
    public static function terminalTest(string $endpoint): string
    {
        return 'read -rs T; printf \'header = "Authorization: Bearer %s"\n\' "$T" | curl -sS --proto \'=https\' --max-time 20 -K - -H \'Content-Type: application/json\' -H \'Accept: application/json, text/event-stream\' -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"'.McpAccess::MCP_PROTOCOL_VERSION.'","capabilities":{},"clientInfo":{"name":"poson-check","version":"1"}}}\' -w \'\nHTTP %{http_code}\n\' \''.$endpoint.'\'; unset T';
    }
}
