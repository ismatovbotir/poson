<?php

namespace App\Enums;

/**
 * What a chat rule does (plan 28 §4). A fixed list, not a workflow engine:
 * the skill behind each case is code. product_search, order_status,
 * repair_status and lead_dialog answer "ask a manager" until their phases.
 */
enum ChatAction: string
{
    case Answer = 'answer';
    case Faq = 'faq';
    case CompanyInfo = 'company_info';
    case ProductSearch = 'product_search';
    case OrderStatus = 'order_status';
    case RepairStatus = 'repair_status';
    case LeadDialog = 'lead_dialog';
    case Handoff = 'handoff';
    case Link = 'link';
    case Menu = 'menu';

    public function label(): string
    {
        return __("enums.chat_action.{$this->value}");
    }
}
