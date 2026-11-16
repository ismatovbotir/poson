<?php

namespace App\Actions\Catalog\Ai;

/**
 * The timeline line of an AI create / improve (plan 32 PW-19): field keys
 * and counts only, never a text, a fact or a model answer.
 */
final class AiDraftActivity
{
    /**
     * @param  list<string>  $fields  text field keys
     * @param  array<string, int>  $images  added / replaced / removed / alts
     * @param  list<string>  $info  step 1 keys the user changed
     */
    public static function body(array $fields, int $specs, array $images, array $info = []): string
    {
        $parts = [];

        if ($fields !== []) {
            $parts[] = 'fields: '.implode(', ', $fields);
        }

        if ($info !== []) {
            $parts[] = 'info: '.implode(', ', $info);
        }

        $parts[] = 'specs: '.$specs;

        foreach (['added', 'replaced', 'removed', 'alts'] as $key) {
            $parts[] = "images.{$key}: ".(int) ($images[$key] ?? 0);
        }

        return implode('; ', $parts);
    }
}
