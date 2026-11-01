<?php

namespace App\Mcp\Tools\Account;

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Support\UntrustedText;
use App\Mcp\Tools\PosonTool;
use App\Models\ApiToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * get_my_access (plan 13 §6, C24): who Claude acts as, with which role,
 * which token (label and dates only) and which tool groups. Never the
 * token, its prefix or hash, IPs, other users or settings.
 */
#[Name('get_my_access')]
#[Description('Shows who you are connected as on the Poson admin: the user name and role, the label of the MCP token in use with its dates (ISO 8601, Asia/Tashkent) and when it will be auto-revoked if left unused, and which tool groups are available. Call it first when unsure what you may do.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMyAccess extends PosonTool
{
    public const TIMEZONE = 'Asia/Tashkent';

    protected function permission(): ?string
    {
        return null;
    }

    public function group(): string
    {
        return 'account';
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $token = request()->attributes->get(AuthenticateMcpToken::ATTRIBUTE);
        $token = $token instanceof ApiToken ? $token : null;

        $groups = collect(AdminServer::TOOLS)
            ->map(fn (string $class): PosonTool => app($class))
            ->filter(fn (PosonTool $tool): bool => $tool->permits($user))
            ->map(fn (PosonTool $tool): string => $tool->group())
            ->unique()->values()->all();

        return Response::structured([
            'user' => [
                'name' => UntrustedText::clean($user->name, 100),
                'role' => $user->staffRole()?->value,
            ],
            'auth_type' => 'token',
            'access_granted_at' => $this->date($user->mcp_access_granted_at),
            'token' => $token === null ? null : [
                'label' => UntrustedText::clean($token->name, 100),
                'created_at' => $this->date($token->created_at),
                'last_used_at' => $this->date($token->last_used_at),
                'idle_revoke_at' => $this->date($token->idleRevokeAt()),
                'expires_at' => null,
            ],
            'tool_groups' => $groups,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'user' => $schema->object([
                'name' => $schema->string()->nullable(),
                'role' => $schema->string()->nullable(),
            ])->required(),
            'auth_type' => $schema->string()->required(),
            'access_granted_at' => $schema->string()->nullable(),
            'token' => $schema->object([
                'label' => $schema->string()->nullable(),
                'created_at' => $schema->string()->nullable(),
                'last_used_at' => $schema->string()->nullable(),
                'idle_revoke_at' => $schema->string()->nullable(),
                'expires_at' => $schema->string()->nullable(),
            ])->nullable(),
            'tool_groups' => $schema->array()->items($schema->string())->required(),
        ];
    }

    private function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()->setTimezone(self::TIMEZONE)->toIso8601String();
    }
}
