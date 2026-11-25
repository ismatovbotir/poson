<?php

namespace App\Enums;

/**
 * Virtual office agents (plan 41 §5–§6): the router (Haiku) picks one of the
 * role agents (Sonnet) or "other" (router only, no draft). "other" has no
 * agent profile. The role only picks the agent and the lead type, never
 * visibility or assignment (VO-7).
 */
enum AgentRole: string
{
    case Router = 'router';
    case Sales = 'sales';
    case Service = 'service';
    case Support = 'support';
    case Other = 'other';

    public function label(): string
    {
        return __("enums.agent_role.{$this->value}");
    }

    /**
     * Roles that have an editable agent profile (Education).
     *
     * @return list<self>
     */
    public static function profiles(): array
    {
        return [self::Router, self::Sales, self::Service, self::Support];
    }

    /**
     * Roles the router may answer with.
     *
     * @return list<self>
     */
    public static function routable(): array
    {
        return [self::Sales, self::Service, self::Support, self::Other];
    }

    /**
     * The role agents behind the router (they write a summary and a draft).
     *
     * @return list<self>
     */
    public static function agents(): array
    {
        return [self::Sales, self::Service, self::Support];
    }

    public function isAgent(): bool
    {
        return in_array($this, self::agents(), true);
    }

    public function usageChannel(): ?AiUsageChannel
    {
        return match ($this) {
            self::Router => AiUsageChannel::OfficeRoute,
            self::Sales => AiUsageChannel::OfficeSales,
            self::Service => AiUsageChannel::OfficeService,
            self::Support => AiUsageChannel::OfficeSupport,
            self::Other => null,
        };
    }

    /**
     * The lead type a role creates (only sales and service create leads).
     */
    public function leadType(): ?LeadType
    {
        return match ($this) {
            self::Sales => LeadType::Quote,
            self::Service => LeadType::ServiceRequest,
            default => null,
        };
    }
}
