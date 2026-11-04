<?php

return [
    'title' => 'Monitoring',
    'subtitle' => 'Rejalashtiruvchi, navbat ishlovchisi, rejali vazifalar va xatolikka uchragan topshiriqlar.',
    'close' => 'Yopish',
    'confirm_required' => 'Vazifalarni ishga tushirish yoki xatolikka uchragan topshiriqlarni oʻzgartirish uchun parolni tasdiqlang (:minutes daqiqa amal qiladi).',
    'confirm_button' => 'Parolni tasdiqlash',

    'retry_after_warning' => 'Navbatning retry_after qiymati :retry_after s va «Hozir ishga tushirish» vazifasi taymautidan (:timeout s) katta emas: uzoq vazifa ikki marta ishga tushishi mumkin. Serverda DB_QUEUE_RETRY_AFTER=660 qiymatini belgilang.',

    'units' => ['ms' => 'ms', 'min' => 'daq'],

    'telegram_problem_open' => 'Bot sahifasini ochish',

    'cards' => [
        'cron' => 'Rejalashtiruvchi (cron)',
        'worker' => 'Navbat ishlovchisi',
        'queue' => 'Navbat',
        'alerts' => 'Ogohlantirishlar',
        'never' => 'Signal yoʻq',
        'down' => 'Javob bermayapti',
        'ok' => 'Ishlayapti',
        'last_tick' => 'Oxirgi signal',
        'lag' => 'Navbat kechikishi',
        'pending' => 'Kutmoqda',
        'oldest' => 'Eng eskisi',
        'failed' => 'Xatolikka uchragan',
        'ping' => 'Tashqi tekshiruv',
        'configured' => 'Sozlangan',
        'not_configured' => 'Kiritilmagan',
        'set_up' => 'Integratsiyalarda sozlash',
        'set_up_telegram' => 'Telegram sozlamalarida sozlash',
    ],

    'tasks' => [
        'title' => 'Rejali vazifalar',
        'hint' => 'Jadvaldagi har bir vazifaning oxirgi ishga tushirilishi. Vaqt Toshkent vaqti.',
        'empty' => 'Jadval nusxasi hali yoʻq. U birinchi monitoring tekshiruvidan keyin paydo boʻladi.',
        'no_snapshot' => 'Jadval nusxasi hali yoʻq: rejalashtiruvchi (cron) monitorni hali ishga tushirmagan.',
        'stale' => 'Jadval nusxasi eskirgan (:time). Cron ishlayotganini tekshiring.',
        'task' => 'Vazifa',
        'last_run' => 'Oxirgi ishga tushirish',
        'status' => 'Holat',
        'duration' => 'Davomiyligi',
        'next_run' => 'Keyingi ishga tushirish',
        'actions' => 'Amallar',
        'run_now' => 'Hozir ishga tushirish',
        'show_runs' => 'Tarix',
        'hide_runs' => 'Yashirish',
        'last_runs' => 'Oxirgi :count ta ishga tushirish',
    ],

    'failed' => [
        'title' => 'Xatolikka uchragan topshiriqlar',
        'hint' => 'Faqat topshiriq nomi va qisqa xatolik koʻrsatiladi, topshiriq maʼlumotlari koʻrsatilmaydi.',
        'empty' => 'Xatolikka uchragan topshiriqlar yoʻq.',
        'job' => 'Topshiriq',
        'queue' => 'Navbat',
        'failed_at' => 'Xatolik vaqti',
        'error' => 'Xatolik',
        'select' => 'Topshiriqni tanlash',
        'details' => 'Batafsil',
        'retry' => 'Qayta urinish',
        'forget' => 'Oʻchirish',
        'retry_selected' => 'Tanlanganlarni qayta urinish',
        'forget_selected' => 'Tanlanganlarni oʻchirish',
        'stale_warning' => ':hours soatdan eski: xabar endi dolzarb boʻlmasligi mumkin.',
        'detail_title' => 'Xatolikka uchragan topshiriq',
        'exception' => 'Xatolik turi',
        'message' => 'Xabar',
        'frame' => 'Qayerda',
    ],

    'job_labels' => [
        'send_telegram_message' => 'Telegram xabari',
        'send_telegram_alerts' => 'Telegram ogohlantirishlari',
        'send_lead_to_telegram' => 'Yangi soʻrov haqida ogohlantirish',
        'notify_finance_of_price_sync_issues' => 'Narxlarni sinxronlash ogohlantirishi',
        'send_telegram_inbox_alert' => 'Mijoz xabari haqida ogohlantirish',
        'forward_telegram_media' => 'Mijoz faylini nusxalash',
    ],

    'confirm' => [
        'run_title' => 'Vazifani hozir ishga tushirasizmi?',
        'run_body' => ':task vazifasi navbatga qoʻyiladi va jadvaldan tashqari bir marta bajariladi.',
        'retry_title' => 'Tanlangan topshiriqlarni qayta urinasizmi?',
        'retry_body' => 'Topshiriqlar navbatga qaytadi. Eski Telegram xabarlari kech kelishi mumkin.',
        'forget_title' => 'Xatolikka uchragan topshiriqlarni oʻchirasizmi?',
        'forget_body' => 'Xatolikka uchragan topshiriqlar butunlay oʻchiriladi.',
    ],

    'toasts' => [
        'run_queued' => 'Vazifa navbatga qoʻyildi',
        'run_queued_body' => 'U monitoring navbatida bajariladi; natija jadvalda koʻrinadi.',
        'retried' => 'Navbatga qaytarilgan topshiriqlar: :count',
        'forgotten' => 'Oʻchirilgan topshiriqlar: :count',
    ],

    'errors' => [
        'invalid' => 'Bu soʻrovga ruxsat berilmagan.',
        'queued' => 'Bu vazifa allaqachon navbatda yoki bajarilmoqda.',
        'running' => 'Vazifa allaqachon bajarilmoqda.',
        'cooldown' => 'Vazifa yaqinda ishga tushirilgan. Bir necha daqiqadan keyin urinib koʻring.',
        'rate' => 'Amallar juda koʻp. Keyinroq urinib koʻring.',
        'too_many' => 'Juda koʻp topshiriq tanlandi (koʻpi bilan 50).',
        'class' => 'Bu topshiriqlarni faqat oʻchirish mumkin, qayta urinib boʻlmaydi.',
    ],
];
