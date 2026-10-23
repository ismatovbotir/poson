<?php

/*
| AI assistant (plan 02): text helper in the admin panel.
*/

return [

    'button' => [
        'label' => 'AI',
        'title' => 'AI assistant: write or translate the text',
    ],

    'assistant' => [
        'title' => 'AI assistant',
        'field' => 'Field',
        'mode' => 'Mode',
        'mode_generate' => 'Generate (uz/ru/en)',
        'mode_translate' => 'Translate from :locale',
        'translate_empty' => 'Fill in the :locale text first to translate it.',
        'hint' => 'Instructions',
        'hint_reset' => 'Default instructions',
        'hint_help' => 'You can edit the instructions. The record data (name, category, brand, specs) is added automatically.',
        'generate' => 'Generate',
        'translate' => 'Translate',
        'remaining' => 'Left today: :count of :limit',
        'pending' => 'AI is writing…',
        'retry' => 'Retry',
        'source' => 'Source',
        'brand_warning' => 'May break the brand rule, please check:',
        'review_uz' => 'Check before publishing: an editor should review the Uzbek text.',
        'apply_locale' => 'Apply to :locale',
        'apply_all' => 'Apply all',
        'apply_help' => 'The text goes into the form but is not saved. Review it and save the form as usual.',
        'too_long' => 'Longer than :max characters: shorten it or generate again.',
    ],

    'applied_title' => 'Text applied',
    'applied_one' => 'The :locale text is in the form. Remember to save.',
    'applied_all' => 'The texts are in the form. Remember to save.',

    'errors' => [
        'timeout' => 'The AI took too long to answer. Please try again.',
        'rate_limited' => 'Too many requests to the AI service. Try again in a minute.',
        'provider_error' => 'The AI service returned an error. Please try again.',
        'invalid_output' => 'The AI answer was incomplete. Please try again.',
        'not_configured' => 'Key not set up: add the Anthropic key in Settings → Integrations.',
        'disabled' => 'AI is switched off. It can be enabled in Settings → Integrations.',
        'quota_exceeded' => 'Daily limit reached. You can continue tomorrow.',
        'budget_exceeded' => 'Monthly budget used up. Please contact an administrator.',
        'unknown_field' => 'The AI assistant is not available for this field.',
        'hint_invalid' => 'Enter the instructions (up to :max characters).',
        'source_empty' => 'There is no text to translate.',
        'unknown_locale' => 'Unknown language. Choose uz, ru or en.',
    ],

    'usage' => [
        'title' => 'Usage & limits',
        'settings' => 'AI settings',
        'not_configured' => 'Key not set up: the Anthropic key is missing, so AI is unavailable.',
        'disabled' => 'AI is switched off. The buttons are hidden in the forms.',
        'today' => 'Today',
        'daily_limit' => ':limit per user per day',
        'month' => 'This month',
        'requests' => 'Number of requests',
        'month_cost' => 'Spent this month',
        'budget' => 'Monthly budget: :budget',
        'no_budget' => 'No monthly budget set',
        'budget_used' => 'Budget used',
        'per_user' => 'By user',
        'per_user_help' => 'Today and this month in :timezone time. Rejected requests don’t count towards the limit.',
        'no_requests_month' => 'No AI requests this month yet.',
        'log' => 'Request log',
        'columns' => [
            'user' => 'User',
            'today' => 'Today',
            'month' => 'Month',
            'tokens' => 'Tokens',
            'cost' => 'Spent',
            'status' => 'Status',
            'feature' => 'Task',
            'model' => 'Model',
            'duration' => 'Duration',
            'source_locale' => 'Source language',
        ],
        'filters' => [
            'all_users' => 'All users',
            'all_statuses' => 'All statuses',
            'all_features' => 'All tasks',
            'clear' => 'Clear filters',
        ],
        'no_results' => 'Nothing found',
        'no_results_body' => 'Try changing the filters.',
        'empty_title' => 'No AI requests yet',
        'empty_body' => 'Requests sent with the AI button in the forms appear here.',
        'deleted_user' => 'Deleted user',
        'brand_warnings' => 'Brand warning',
        'pruned' => 'Instruction and answer texts were removed after 90 days.',
        'prompt' => 'Instructions',
        'seconds' => ':value s',
    ],

    'languages' => [
        'uz' => 'Uzbek',
        'ru' => 'Russian',
        'en' => 'English',
    ],

    /*
    | Default instructions per field (editable in the panel). Placeholders:
    | {name} {category} {brand} {specs} {question}; {from} = source language.
    */
    'hints' => [
        'translate' => 'Translate the {from} text into the other languages. Keep the meaning, the terms and the formatting; write naturally for each language.',
        'product' => [
            'name' => 'Write the catalog name of this product: product type, brand and model, for example "Label printer Zebra ZD421". Current name: {name}. Brand: {brand}. Category: {category}.',
            'short_description' => 'Write a short description (1–2 sentences) of {name} for the product card: what it is and which business tasks it is for. Category: {category}.',
            'description' => 'Write the product page description of {name} ({brand}, {category}) for business buyers: purpose and typical use, the main features based on the specs, and how Poson helps (selection, setup, official warranty, service centre). 2–4 short paragraphs or a paragraph and a list.',
            'meta_title' => 'Write an SEO title for the product page of {name}: product type and model first, then "Poson". No prices.',
            'meta_description' => 'Write an SEO meta description for {name} ({category}): what it is and one service benefit of buying from Poson (setup, official warranty, service). No prices.',
        ],
        'category' => [
            'name' => 'Suggest a clear catalog category name for "{name}". Parent category: {category}. Short, plural where natural.',
            'description' => 'Write the introduction of the catalog category "{name}" (parent: {category}): what these products are used for, which specs matter when choosing, and how Poson helps with selection, setup and service. 2–3 short paragraphs.',
            'meta_title' => 'Write an SEO title for the catalog category "{name}", ending with "Poson". No prices.',
            'meta_description' => 'Write an SEO meta description for the catalog category "{name}": what the customer finds here and one service benefit of Poson (expert selection, setup, official warranty). No prices.',
        ],
        'brand' => [
            'description' => 'Write a short introduction to the brand {name} for its catalog page: what kind of equipment it makes, and that Poson helps choose, set up and service it. Do not invent history, figures or awards.',
        ],
        'faq' => [
            'question' => 'Suggest one frequently asked question a business customer may have about {name}. Category: {category}.',
            'answer' => 'Write a clear answer to the question "{question}" (topic: {name}). Use only facts from the record; if the answer depends on the model or the order, say so and offer to contact Poson.',
        ],
    ],

];
