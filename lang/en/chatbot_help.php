<?php

/**
 * Instructions page of the rule chat bot (plan 28 §15): short steps, per permission. Update when a Chat bot screen changes.
 */
return [
    'title' => 'Chat bot: instructions',
    'subtitle' => 'Short steps for working with the bot. You only see the parts you can use.',
    'contents' => 'Contents',
    'example_columns' => [
        'Keyword',
        'Match type',
        'Matches',
        'Does not match',
    ],
    'sections' => [
        'how' => [
            'title' => 'How the bot works',
            'intro' => 'The bot is not AI. It answers by the rules you write: a keyword leads to a ready answer, a button or an action.',
            'steps' => [
                'A customer writes a message. The bot cleans it up and looks for the keywords of your rules.',
                'Each matched keyword adds its weight to the score. The rule with the highest score wins if it reaches the minimum score.',
                'If nothing fits, the bot shows a short reply, the topic menu and a button to ask a manager, and the phrase goes to Teach.',
                'There are two modes: in bot mode the bot answers; in manager mode the customer’s messages go to the staff inbox. A staff reply switches the chat to manager mode. It goes back to the bot from the menu or /start, or after 8 hours of silence (never while a customer is waiting for an answer).',
                'Telegram and the website differ: in Telegram the bot answers registered customers only; order and repair status are checked in Telegram only, never on the website.',
            ],
            'note' => 'The bot never makes answers up: it only says what you wrote.',
        ],
        'teaching' => [
            'title' => 'Teaching the bot: rules and keywords',
            'steps' => [
                'Open Chat bot → Rules and press Add rule.',
                'Give it an internal name and pick an action. For a plain answer, write the text in all three languages (Uzbek is required).',
                'Add keywords: type the word or phrase customers use and choose how it must match.',
                'Weight (1–100) says how strong a keyword is: 10 for normal words, more for exact phrases. Priority is added to the score and breaks ties.',
                'The “Matched as” line under a keyword shows what the bot compares. You do not need extra keywords for apostrophes (oʻrnatish, o\'rnatish) or Cyrillic spellings (ўрнатиш): they all become the same text.',
                'Check the rule in the Test console, then save.',
            ],
            'examples' => [
                ['yetkazib berish', 'Phrase', 'yetkazib berish bormi? · Етказиб бериш борми?', 'only “yetkazib”'],
                ['dostav', 'Prefix', 'dostavka · доставка · доставку', 'podostavit'],
                ['narx', 'Word', 'narx qancha? · Нарх қанча?', 'narxnoma'],
                ['salom', 'Exact', 'salom · Салом!', 'salom, narx qancha'],
            ],
            'note' => 'Word is a whole word only; Phrase is the words in this order; Prefix is the start of a word (handy for endings); Exact is the whole message.',
        ],
        'unmatched' => [
            'title' => 'Teaching from unmatched phrases',
            'steps' => [
                'Every day open Chat bot → Teach: the phrases the bot did not understand, the most frequent first.',
                'If the phrase fits an existing topic, press Add as keyword, choose the rule and the match type, and save. The phrase is resolved at once.',
                'If it is a new topic, press Create a rule: the form opens with the phrase as the first keyword.',
                'If it is noise, press Ignore.',
            ],
            'note' => 'A # in a phrase means personal data (phone, card number, passport, email, link) was hidden before saving. Such parts are never stored.',
        ],
        'console' => [
            'title' => 'Test console',
            'steps' => [
                'Open Chat bot → Test console, type a customer phrase, pick the language and channel and press Check.',
                'You see how the phrase is normalized, which rule won and the score of each matched keyword.',
                'Below is the reply the customer would get, with its buttons.',
                'If nothing matched, you see the general fallback reply.',
            ],
            'note' => 'The console never sends or saves anything. For rules that would look up an order, start a request, search products or call a manager it only says “would run”.',
        ],
        'answers' => [
            'title' => 'Answers, buttons and links',
            'steps' => [
                'An answer is plain text, up to 1000 characters per language. HTML and Markdown are not supported; line breaks are kept.',
                'A button label is at most 40 characters; a rule can have up to 8 buttons. A button leads to another rule, a site page or (Admin only) an allowed external https address.',
                'Write about service: expert selection, setup, warranty, our own service centre, support, delivery. Do not sell on price.',
                'If a text pushes price or special offers, it is refused when you save: reword it.',
            ],
        ],
        'stats' => [
            'title' => 'Stats',
            'steps' => [
                'Choose the period (7, 30 or 90 days) and, if needed, the channel.',
                'Answered by a rule is how often the bot found an answer. Did not understand is phrases without a rule. Passed to a manager is customers who asked for a person. The other cards: requests left, status found or not found, search with no result.',
                'Many “did not understand”: open Teach and add keywords.',
                'Many hand-offs on one topic: write a better answer for that topic.',
            ],
        ],
        'settings' => [
            'title' => 'Settings',
            'steps' => [
                'Admin only: switches the Telegram bot and the website chat on or off. Switching on is refused until the privacy page is published and reviewed.',
                'Content managers edit the texts (greeting, fallback, after hours, consent line), working hours, hand-off target and widget options.',
                'The hand-off target says whether a chat goes to account managers, the service team or any staff member. “Back to the bot after” is the silence time (usually 8 hours).',
                'The limits at the bottom are read only.',
            ],
            'note' => 'Every change is written to the settings history (who, when, which setting; never the text).',
        ],
        'inbox' => [
            'title' => 'Chats inbox for staff',
            'steps' => [
                'Open Chat bot → Chats: unread conversations are at the top.',
                'Open a chat and take it, or it stays unassigned for any manager with access.',
                'Write the reply: once sent, the chat switches to manager mode and the bot stays silent.',
                'Convert the chat to a lead when the customer wants a quote or a service. Close it when it is done (a closed website chat ends).',
                'Block abusers: their messages stop arriving.',
            ],
        ],
    ],
];
