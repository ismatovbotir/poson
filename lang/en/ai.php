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
        'not_configured' => 'Key not set up: add the key of the selected AI provider (Anthropic or OpenRouter) in Settings → Integrations.',
        'disabled' => 'AI is switched off. It can be enabled in Settings → Integrations.',
        'quota_exceeded' => 'Daily limit reached. You can continue tomorrow.',
        'budget_exceeded' => 'Monthly budget used up. Please contact an administrator.',
        'no_credits' => 'The OpenRouter account has no credits left. Ask an administrator to top it up or choose a free model.',
        'model_unavailable' => 'The selected AI model is not available right now. Ask an administrator to choose another model in Settings → Integrations.',
        'invalid_key' => 'The AI provider rejected the key. Ask an administrator to check it in Settings → Integrations.',
        'refused' => 'The AI provider refused this request (for example, by its content moderation). Change the instructions or ask an administrator to choose another model.',
        'bad_request' => 'The AI provider rejected the request as invalid. Ask an administrator to check the AI model in Settings → Integrations.',
        'field_retired' => 'The AI assistant is no longer available for this field.',
        'unknown_field' => 'The AI assistant is not available for this field.',
        'legal_body' => 'AI does not write the text of legal pages: edit it by hand with the lawyer.',
        'hint_invalid' => 'Enter the instructions (up to :max characters).',
        'source_empty' => 'There is no text to translate.',
        'unknown_locale' => 'Unknown language. Choose uz, ru or en.',
    ],

    'usage' => [
        'title' => 'Usage & limits',
        'settings' => 'AI settings',
        'not_configured' => 'Key not set up: the :provider key is missing, so AI is unavailable.',
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
            'provider' => 'Provider',
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
    | Sample record of each field group, used to preview a prompt in
    | Settings → AI prompts (plan 12).
    */
    'samples' => [
        'products' => ['name' => 'Zebra ZD421 label printer', 'brand' => 'Zebra', 'category' => 'Printers › Label printers'],
        'categories' => ['name' => 'Label printers', 'category' => 'Printers'],
        'brands' => ['name' => 'Zebra'],
        'pages' => ['name' => 'Delivery and payment'],
        'news' => ['name' => 'Zebra ZD421 printers back in stock'],
        'articles' => ['name' => 'How to choose a barcode scanner'],
        'faq' => ['name' => 'Label printers', 'category' => 'Printers', 'question' => 'Which labels fit this printer?'],
        'banners' => ['name' => 'Barcode scanners', 'category' => 'Scanners'],
    ],

    /*
    | Default instructions per field (editable in the panel; an admin override
    | in Settings → AI prompts replaces them, plan 12). Allowed placeholders per
    | field: FieldSpec::$placeholders (never {specs}); {from} = source language.
    */
    'hints' => [
        'translate' => 'Translate the {from} text into the other languages. Keep the meaning, the terms and the formatting; write naturally for each language.',
        'product' => [
            'short_description' => 'Write a short description (1–2 sentences) of {name} for the product card: what it is and which business tasks it is for. Category: {category}.',
            'description' => 'Write the product page description of {name} ({brand}, {category}) for business buyers: purpose and typical use, the main features based on the specs, and how Poson helps (selection, setup, official warranty, service centre). 2–4 short paragraphs or a paragraph and a list.',
            'meta_title' => 'Write an SEO title for the product page of {name}: product type and model first, then "Poson". No prices.',
            'meta_description' => 'Write an SEO meta description for {name} ({category}): what it is and one service benefit of buying from Poson (setup, official warranty, service). No prices.',
            'image_alt' => 'Write a short alt text for a photo of {name} ({brand}, {category}): one descriptive phrase with the product type, brand and model, without “photo of” or “image of” and without marketing words. The image position (main or n of N) is in the context: mention it only if it helps tell the photos apart. No prices.',
        ],
        'category' => [
            'description' => 'Write the introduction of the catalog category "{name}" (parent: {category}): what these products are used for, which specs matter when choosing, and how Poson helps with selection, setup and service. 2–3 short paragraphs.',
            'meta_title' => 'Write an SEO title for the catalog category "{name}", ending with "Poson". No prices.',
            'meta_description' => 'Write an SEO meta description for the catalog category "{name}": what the customer finds here and one service benefit of Poson (expert selection, setup, official warranty). No prices.',
        ],
        'brand' => [
            'description' => 'Write a short introduction to the brand {name} for its catalog page: what kind of equipment it makes, and that Poson helps choose, set up and service it. Do not invent history, figures or awards.',
            'meta_title' => 'Write an SEO title for the brand page of {name}: the brand and the main types of equipment it makes, ending with “Poson”. No prices.',
            'meta_description' => 'Write an SEO meta description for the brand page of {name}: which equipment the brand makes and that Poson supplies it with expert selection, setup, official warranty and service. No prices.',
        ],
        'page' => [
            'body' => 'Write the text of the website page “{name}” for business customers, based on the current text and facts in the context: clear sections with H2 headings, short paragraphs and lists. Stress Poson’s service (expert selection, setup, official warranty, own service centre). Do not invent addresses, phone numbers, prices or terms.',
            'meta_title' => 'Write an SEO title for the website page “{name}”, ending with “Poson”. No prices.',
            'meta_description' => 'Write an SEO meta description for the website page “{name}”: what the visitor finds on it. No prices.',
        ],
        'news' => [
            'excerpt' => 'Write a one or two sentence summary of the news “{name}” for the news list, based on the text in the context. No prices.',
            'body' => 'Write the text of the Poson news “{name}” for business customers, based on the current text and the facts in the context: a short lead, then sections with H2 headings, short paragraphs and lists. Stress Poson’s service (expert selection, setup, official warranty, own service centre). Do not invent dates, figures, prices or quotes.',
            'meta_title' => 'Write an SEO title for the news “{name}”, ending with “Poson”. No prices.',
            'meta_description' => 'Write an SEO meta description for the news “{name}”: what the reader learns. No prices.',
        ],
        'article' => [
            'excerpt' => 'Write a one or two sentence summary of the guide “{name}” for the article list: which practical task it helps with and who it is for. No prices.',
            'body' => 'Write the practical guide “{name}” for business customers, based on the current text and the facts in the context. This is a how-to, not a news post: sections with H2 headings (steps, selection criteria, common mistakes, a short checklist), short paragraphs and lists. Briefly explain technical terms. Use only facts from the context; do not invent figures, dates or specs. End with one paragraph on how Poson helps (expert selection, setup and integration, official warranty, own service centre). No prices.',
            'meta_title' => 'Write an SEO title for the guide “{name}” that matches the search intent (for example “How to choose…” or “… guide”), ending with “Poson”. No prices.',
            'meta_description' => 'Write an SEO meta description for the article “{name}”: what the reader learns and for which task. No prices.',
        ],
        'faq' => [
            'answer' => 'Write a clear answer to the question "{question}" (topic: {name}). Use only facts from the record; if the answer depends on the model or the order, say so and offer to contact Poson.',
        ],
        'banner' => [
            'title' => 'Write a headline (up to 90 characters) for the banner “{name}” (home slider or the {category} category banner): one clear service benefit of Poson (expert selection, setup and integration, official warranty, own service centre, stock and delivery). No prices, no urgency or time limits.',
            'text' => 'Write a subtitle (up to 200 characters, one or two sentences) for the banner “{name}” (home slider or the {category} category banner) that supports the headline with a concrete service benefit of Poson. No prices, no urgency or time limits.',
        ],
    ],

];
