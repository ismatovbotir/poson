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
];
