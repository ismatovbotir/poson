<?php

/*
| Xodimlar hisoblari (24-reja): oʻz parolini almashtirish, parol qoidasi, tiklash buyrugʻi, ogohlantirishlar.
*/

return [
    'account' => [
        'menu' => 'Parolni almashtirish',
        'title' => 'Parolni almashtirish',
        'subtitle' => 'Joriy parolni kiriting, soʻng yangisini tanlang.',
        'forced_notice' => 'Siz vaqtinchalik parol bilan kirdingiz. Davom etish uchun oʻz parolingizni oʻrnating. Vaqtinchalik parol :date da ishlashdan toʻxtaydi.',
        'sessions_notice' => 'Bu brauzerda seans saqlanadi. Boshqa barcha qurilmalarda seans yakunlanadi.',
        'mcp_warning' => 'Parol almashtirilganda MCP tokenlaringiz bekor qilinadi. Yangi token yaratmaguningizcha Claude ulana olmaydi.',
        'current_password' => 'Joriy parol',
        'new_password' => 'Yangi parol',
        'new_password_hint' => 'Kamida :min ta belgi. Bir-biriga bogʻliq boʻlmagan bir nechta soʻz yaxshi variant.',
        'confirm_new_password' => 'Yangi parolni takrorlang',
        'submit' => 'Parolni almashtirish',
        'cancel' => 'Bekor qilish',
        'changed_title' => 'Parol almashtirildi',
        'changed_body' => 'Boshqa qurilmalardagi seanslar yakunlandi.',
    ],

    'password_rule' => [
        'too_short' => 'Yangi parolda kamida :min ta belgi boʻlishi kerak.',
        'too_long' => 'Yangi parol :max baytdan oshmasligi kerak. Qisqaroq parol tanlang.',
        'personal' => 'Yangi parolda ismingiz, e-mailingiz yoki kompaniya nomi boʻlmasligi kerak.',
        'run' => 'Yangi parolda bitta belgi ketma-ket bir necha marta takrorlanmasligi kerak.',
        'common' => 'Bu parol juda keng tarqalgan. Boshqasini tanlang.',
        'same_as_current' => 'Yangi parol joriy paroldan farq qilishi kerak.',
    ],

    'reset_command' => [
        'confirm' => 'Hisob paroli almashtiriladi va uning barcha seanslari yakunlanadi. Davom etilsinmi?',
        'cancelled' => 'Bekor qilindi. Hech narsa oʻzgarmadi.',
        'refused' => 'Rad etildi. Hech narsa oʻzgarmadi.',
        'done' => 'Vaqtinchalik parol, faqat bir marta koʻrsatiladi. U :hours soat amal qiladi va birinchi kirishda almashtirilishi kerak. Uni shaxsan yoki telefon orqali yetkazing:',
        'alert_undeliverable' => 'Rahbariyat chatini ogohlantirib boʻlmadi. Bu tiklash haqida jamoaga oʻzingiz xabar bering.',
    ],

    'alerts' => [
        'password_reset_cli' => 'Xodim paroli server konsolidan tiklandi',
    ],
];
