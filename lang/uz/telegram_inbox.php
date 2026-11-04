<?php

/*
| Staff inbox of the Telegram bot (plan 21 A1): Admin → Telegram → Chats.
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
        'unregistered' => 'Tashrifchi botda roʻyxatdan oʻtishni tugatmagan.',
    ],
    'show' => [
        'title' => 'Suhbat',
        'subtitle' => 'Chat №:id',
        'back' => 'Chatlarga qaytish',
        'load_older' => 'Oldingi xabarlarni yuklash',
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

];
