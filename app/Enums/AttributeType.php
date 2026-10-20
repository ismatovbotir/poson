<?php

namespace App\Enums;

/**
 * Value type of a library attribute (plan 03 §2). select and multiselect
 * take their values from attribute_options; text is never a filter.
 */
enum AttributeType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Multiselect = 'multiselect';

    public function label(): string
    {
        return __("enums.attribute_type.{$this->value}");
    }

    /**
     * Values are picked from attribute_options.
     */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Select, self::Multiselect], true);
    }

    /**
     * Free text can't be a public filter.
     */
    public function canBeFilterable(): bool
    {
        return $this !== self::Text;
    }

    /**
     * Badge color for <x-admin.badge>.
     */
    public function color(): string
    {
        return match ($this) {
            self::Text => 'slate',
            self::Number => 'sky',
            self::Boolean => 'amber',
            self::Select => 'brand',
            self::Multiselect => 'violet',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
