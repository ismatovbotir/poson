<?php

/*
| Staff inbox of the Telegram bot (plan 21 stream A): Admin → Telegram → Chats.
*/

return [

    'title' => 'Chatlar',
    'subtitle' => 'Mijozlarning Telegram-bot bilan yozishmasi',
    'search_placeholder' => 'Ism, username, kompaniya yoki telefon',
    'unnamed' => 'Ismsiz mijoz',
    'unassigned' => 'Tayinlanmagan',
    'open' => 'Suhbatni ochish',
    'empty_title' => 'Hozircha suhbatlar yoʻq',
    'empty_body' => 'Mijozlar botga yozgan xabarlar shu yerda paydo boʻladi.',
    'no_results' => 'Hech narsa topilmadi',
    'no_results_body' => 'Filtrlar yoki qidiruvni oʻzgartiring.',
    'unread_count' => ':count ta yangi',
    'scopes' => [
        'all' => 'Hammasi',
        'mine' => 'Meniki',
        'unassigned' => 'Menejersiz',
        'unread' => 'Oʻqilmagan',
    ],
    'filters' => [
        'scope' => 'Koʻrsatish',
        'status' => 'Holat',
        'status_all' => 'Har qanday holat',
        'clear' => 'Filtrlarni tozalash',
    ],
    'columns' => [
        'customer' => 'Mijoz',
        'status' => 'Holat',
        'manager' => 'Menejer',
        'last_message' => 'Oxirgi xabar',
    ],
    'badges' => [
        'unregistered' => 'Roʻyxatdan oʻtmagan',
        'banned' => 'Bloklangan',
        'blocked' => 'Botni bloklagan',
        'stuck' => 'Yetkazish qotib qolgan',
    ],
    'banners' => [
        'banned' => 'Mijoz bloklangan. Uning xabarlari qabul qilinmaydi.',
        'blocked' => 'Mijoz botni bloklagan, shuning uchun xabarlar yetkazilmaydi.',
        'account_changed' => 'Mijozning Telegram akkaunti oʻzgargan. Maʼlumot berishdan oldin kim yozayotganini tekshiring.',
        'bot_off' => 'Bot oʻchirilgan yoki sozlanmagan, shuning uchun javob yuborib boʻlmaydi.',
        'unregistered' => 'Tashrifchi botda roʻyxatdan oʻtishni tugatmagan.',
    ],
    'show' => [
        'title' => 'Suhbat',
        'subtitle' => 'Chat №:id',
        'back' => 'Chatlarga qaytish',
        'load_older' => 'Oldingi xabarlarni yuklash',
        'refresh' => 'Yangilash',
        'older_hidden' => 'Oldingi xabarlar koʻrsatilmagan (oxirgi :count ta koʻrsatilgan).',
        'no_messages' => 'Hozircha xabarlar yoʻq.',
        'from_customer' => 'Mijoz',
        'from_bot' => 'Bot',
        'forward' => 'Jamoa chati',
    ],
    'panel' => [
        'title' => 'Mijoz',
        'name' => 'Ism',
        'username' => 'Username',
        'phone' => 'Telefon',
        'company' => 'Kompaniya',
        'inn' => 'STIR',
        'locale' => 'Til',
        'status' => 'Suhbat',
        'manager' => 'Menejer',
        'registered_at' => 'Roʻyxatdan oʻtgan',
        'last_inbound_at' => 'Mijozning oxirgi xabari',
    ],
    'reply' => [
        'label' => 'Javob',
        'placeholder' => 'Mijozga javob yozing',
        'hint' => 'Oddiy matn, :max belgigacha. Ctrl+Enter yuboradi.',
        'send' => 'Yuborish',
    ],
    'resend' => [
        'retry' => 'Qayta urinish',
        'resend' => 'Yana yuborish',
        'duplicate_warning' => 'Mijoz bu xabarni allaqachon olgan boʻlishi mumkin. Yana yuborilsa, ikki marta yetib borishi mumkin. Davom etasizmi?',
    ],
    'refused' => [
        'unregistered' => 'Tashrifchi roʻyxatdan oʻtishni tugatmagan, shuning uchun bu mavjud emas.',
        'banned' => 'Mijoz bloklangan.',
        'blocked' => 'Mijoz botni bloklagan.',
        'bot_off' => 'Bot oʻchirilgan yoki sozlanmagan.',
        'rate_limited' => 'Xabarlar juda koʻp. Bir daqiqadan keyin urinib koʻring.',
        'invalid_text' => 'Xabar boʻsh yoki juda uzun.',
        'taken' => 'Bu suhbatni boshqa menejer olgan.',
        'not_retryable' => 'Bu xabarni qayta yuborib boʻlmaydi.',
    ],
    'toasts' => [
        'reply_queued' => 'Javob yetkazish navbatiga qoʻyildi.',
        'resent' => 'Xabar yana yetkazish navbatiga qoʻyildi.',
    ],

];
