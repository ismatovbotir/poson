<?php

namespace App\Enums;

/**
 * How the text of an AI-fill field is stored (plan 29): plain text (no
 * markup at all) or the sanitized rich-text HTML of the editor.
 */
enum AiFieldKind: string
{
    case Plain = 'plain';
    case Html = 'html';

    public function label(): string
    {
        return __("enums.ai_field_kind.{$this->value}");
    }
}
