<?php

namespace App\Enums;

/**
 * Kind of api_tokens row (plan 13, C3/C5). Each type has its own prefix and
 * is only accepted by its own middleware:
 * - PriceApi: "pos_" tokens of the 1C price API (plan 09, AuthenticateApiToken);
 * - Mcp: "posm_" tokens of the CEO's MCP connection (AuthenticateMcpToken).
 */
enum ApiTokenType: string
{
    case PriceApi = 'price_api';
    case Mcp = 'mcp';

    public function label(): string
    {
        return __("enums.api_token_type.{$this->value}");
    }

    /**
     * Prefix of the plain token.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::PriceApi => 'pos_',
            self::Mcp => 'posm_',
        };
    }
}
