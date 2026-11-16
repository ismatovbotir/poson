<?php

/**
 * Built-in texts of the rule chat bot (plan 28). Plain text only. Rule
 * answers are edited in Admin → Chat bot; these are the fixed fallbacks.
 */
return [
    'fallback' => 'I did not quite understand. Choose a topic below or ask a manager.',
    'fallback_error' => 'The assistant is not available right now. Please ask a manager.',
    'menu_title' => 'What would you like to know? Choose a topic.',
    'coming_soon' => 'I cannot help with this here yet. Please ask a manager.',
    'search_soon' => 'Product search in the chat is coming soon. Please ask a manager or open the catalog.',
    'handoff' => 'Please write your question here. A manager will reply as soon as possible.',
    'cancelled' => 'Cancelled.',
    'not_available' => 'This option is not available. Choose a topic from the menu.',

    'ai' => [
        'marker' => 'AI assistant · for exact prices and orders, ask a manager',
        'limited' => 'You have asked a lot of questions in a short time. A manager will be glad to help: press the button below.',
    ],

    'buttons' => [
        'handoff' => 'Talk to a manager',
        'menu' => 'Menu',
    ],

    'faq' => [
        'pick' => 'Choose a question:',
        'empty' => 'There are no questions here yet. Please ask a manager.',
        'unavailable' => 'This answer is not available. Please ask a manager.',
    ],

    'company' => [
        'address' => 'Address: :value',
        'hours' => 'Working hours: :value',
        'phone' => 'Phone: :value',
        'email' => 'Email: :value',
        'unavailable' => 'This information is not published yet. Please ask a manager.',
    ],

    'days' => [
        'mo' => 'Mon',
        'tu' => 'Tue',
        'we' => 'Wed',
        'th' => 'Thu',
        'fr' => 'Fri',
        'sa' => 'Sat',
        'su' => 'Sun',
    ],

    'errors' => [
        'action_invalid' => 'Choose a valid action.',
        'key_invalid' => 'The key may contain only lower-case letters, digits and underscores (2 to 60, starting with a letter).',
        'key_taken' => 'This key is already used by another rule.',
        'text_plain' => 'Use plain text only: no "<" and no control characters.',
        'text_too_long' => 'This text is too long.',
        'answer_required' => 'The answer in Uzbek is required for this action.',
        'label_required' => 'The Uzbek label is required.',
        'brand' => 'This text must not advertise low prices or discounts.',
        'url_forbidden' => 'Only an Admin may add an external link, and only to an allowed https address.',
        'target_invalid' => 'Choose an allowed site page for the link.',
        'topic_invalid' => 'Choose what company information to show.',
        'faq_invalid' => 'Choose an active FAQ item.',
        'flow_invalid' => 'Choose a valid request type.',
        'button_invalid' => 'A button needs a label and one valid target.',
        'keyword_invalid' => 'A keyword is empty, too long or has an invalid type or weight.',
        'too_many_rules' => 'The limit of :max rules is reached.',
        'too_many_keywords' => 'A rule can have at most :max keywords.',
        'too_many_buttons' => 'A rule can have at most :max buttons.',
        'system_rule_delete' => 'A built-in rule cannot be deleted. Switch it off instead.',
    ],

    'after_hours' => 'Our team is offline now. We will reply during working hours.',

    'search' => [
        'prompt' => 'Write what you are looking for: a model, an article number or a name.',
        'found' => 'Found:',
        'line' => ':n. :name — :price',
        'none' => 'Nothing found. Try other words or ask a manager.',
        'limited' => 'Too many searches. Please ask a manager or open the catalog.',
        'unavailable' => 'This product is no longer available. Please ask a manager.',
        'brand' => 'Brand: :brand',
        'price' => 'Price: :price',
        'price_on_request' => 'price on request',
        'open' => 'Open on the website',
        'quote' => 'Request a quote',
    ],

    'lookup' => [
        'limited' => 'Too many checks. Please ask a manager.',
        'unavailable' => 'This check is not available right now. Please ask a manager.',
        'since' => 'Status since: :day',
        'status' => 'Status: :status',
        'web' => [
            'order' => 'Order status is available in our Telegram bot after you share your phone number.',
            'repair' => 'Repair status is available in our Telegram bot after you share your phone number.',
        ],
        'order' => [
            'none' => 'No orders found for your phone number.',
            'not_found' => 'No such order found for your phone number. Check the number or ask a manager.',
            'recent' => 'Your latest orders. Choose one:',
            'title' => 'Order :number',
            'payment' => 'Payment: :status',
        ],
        'repair' => [
            'none' => 'No repairs found for your phone number.',
            'not_found' => 'No such repair found for your phone number. Check the number or ask a manager.',
            'recent' => 'Your latest repairs. Choose one:',
            'title' => 'Repair :number',
            'device' => 'Device: :device',
            'awaiting_approval' => 'We are waiting for your approval. A manager will contact you.',
            'ready' => 'Your device is ready for pickup.',
        ],
    ],

    'dialog' => [
        'buttons' => [
            'cancel' => 'Cancel',
            'send' => 'Send',
            'skip' => 'Skip',
        ],
        'callback' => ['details' => 'What should we call you about? Write a short message or skip.'],
        'quote' => [
            'details' => 'Which equipment do you need? Write the models and quantities.',
            'details_product' => 'Write the quantity and any wishes for :product.',
        ],
        'service' => ['details' => 'Describe the device and the problem.'],
        'required' => 'This step cannot be skipped.',
        'confirm' => 'Check your request:',
        'summary' => [
            'type' => 'Type: :type',
            'product' => 'Product: :product',
            'details' => 'Details: :details',
            'phone' => 'Phone: :phone',
        ],
        'sent' => 'Thank you! Your request :number is accepted. A manager will contact you.',
        'expired' => 'The request timed out. Please start again.',
        'limited' => 'Too many requests. Please ask a manager.',
        'refused' => 'We cannot accept this request here. Please ask a manager.',
    ],

    // Website knowledge index (plan 33): replaces a money amount found in site text.
    'knowledge' => [
        'docs' => 'Docs',
        'open_prefix' => 'Open: ',
        'open_button' => 'Open: :title',
        'sources' => 'Source pages:',
        'amount_masked' => '(see the page)',
    ],
];
