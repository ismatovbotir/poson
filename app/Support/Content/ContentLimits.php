<?php

namespace App\Support\Content;

/**
 * Length limits shared by the admin form rules and the AI-fill field list
 * (plan 29), so the prompt can never promise more than save accepts.
 */
final class ContentLimits
{
    /**
     * Names, titles, SEO titles and image alt texts.
     */
    public const SHORT_TEXT_MAX = 255;
}
