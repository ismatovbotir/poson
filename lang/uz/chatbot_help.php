<?php

/**
 * Instructions page of the rule chat bot (plan 28 §15): short steps, per permission. Update when a Chat bot screen changes.
 */
return [
    'title' => 'Chat-bot: yoʻriqnoma',
    'subtitle' => 'Bot bilan ishlash boʻyicha qisqa qadamlar. Sizga mavjud boʻlimlar koʻrsatiladi.',
    'contents' => 'Mundarija',
    'example_columns' => [
        'Kalit soʻz',
        'Moslik turi',
        'Mos keladi',
        'Mos kelmaydi',
    ],
    'sections' => [
        'how' => [
            'title' => 'Bot qanday ishlaydi',
            'intro' => 'Bot sunʼiy intellekt emas. U siz yozgan qoidalar boʻyicha javob beradi: kalit soʻz tayyor javobga, tugmaga yoki harakatga olib boradi.',
            'steps' => [
                'Mijoz xabar yozadi. Bot uni tozalab, qoidalaringizdagi kalit soʻzlarni qidiradi.',
                'Topilgan har bir kalit soʻz oʻz ogʻirligini ballga qoʻshadi. Eng yuqori ballli qoida, agar eng kam balldan kam boʻlmasa, gʻalaba qozonadi.',
                'Hech narsa mos kelmasa, bot qisqa javob, mavzular menyusi va menejerga murojaat tugmasini koʻrsatadi; ibora «Oʻrgatish» roʻyxatiga tushadi.',
                'Ikki rejim bor: bot rejimida bot javob beradi; menejer rejimida mijoz xabarlari xodimlar qutisiga tushadi. Xodim javob yozsa, suhbat menejer rejimiga oʻtadi. Botga menyu yoki /start orqali, yoki 8 soat jimlikdan keyin qaytadi (mijoz javob kutayotgan boʻlsa, qaytmaydi).',
                'Telegram va sayt farq qiladi: Telegramda bot faqat roʻyxatdan oʻtgan mijozlarga javob beradi; buyurtma va taʼmir holati faqat Telegramda tekshiriladi, saytda hech qachon.',
            ],
            'note' => 'Bot oʻzidan javob toqmaydi: u faqat siz yozganni aytadi.',
        ],
        'teaching' => [
            'title' => 'Botni oʻrgatish: qoidalar va kalit soʻzlar',
            'steps' => [
                '«Chat-bot → Qoidalar» boʻlimini oching va «Qoida qoʻshish» tugmasini bosing.',
                'Ichki nom bering va harakatni tanlang. Oddiy javob uchun matnni uch tilda yozing (oʻzbekcha majburiy).',
                'Kalit soʻz qoʻshing: mijozlar yozadigan soʻz yoki iborani kiriting va qanday mos kelishini tanlang.',
                'Ogʻirlik (1–100) kalit soʻz qanchalik kuchli ekanini bildiradi: oddiy soʻzlar uchun 10, aniq iboralar uchun koʻproq. Ustuvorlik ballga qoʻshiladi va teng ballda tanlaydi.',
                'Kalit soʻz ostidagi «Qidiruvda shunday yoziladi» bot nimani solishtirishini koʻrsatadi. Apostrof turlari yoki kirill yozuvi uchun alohida kalit soʻz kerak emas: hammasi bir xil matnga aylanadi.',
                'Qoidani Sinov konsolida tekshiring, keyin saqlang.',
            ],
            'examples' => [
                ['yetkazib berish', 'Ibora', 'yetkazib berish bormi? · kirillchasi ham', 'faqat «yetkazib»'],
                ['dostav', 'Prefiks', 'dostavka · dostavku · kirillchasi ham', 'podostavit'],
                ['narx', 'Soʻz', 'narx qancha? · kirillchasi ham', 'narxnoma'],
                ['salom', 'Aniq', 'salom · kirillchasi ham', 'salom, narx qancha'],
            ],
            'note' => 'Soʻz — faqat butun soʻz; Ibora — soʻzlar shu tartibda; Prefiks — soʻz boshi (qoʻshimchalar uchun qulay); Aniq — butun xabar.',
        ],
        'unmatched' => [
            'title' => 'Tushunilmagan iboralardan oʻrgatish',
            'steps' => [
                'Har kuni «Chat-bot → Oʻrgatish» boʻlimini oching: bot tushunmagan iboralar roʻyxati, eng koʻp uchraydigani birinchi.',
                'Ibora mavjud mavzuga mos boʻlsa, «Kalit soʻz sifatida qoʻshish» tugmasini bosing, qoida va moslik turini tanlab saqlang. Ibora darhol hal qilingan boʻladi.',
                'Yangi mavzu boʻlsa, «Yangi qoida yaratish» tugmasini bosing: forma birinchi kalit soʻz sifatida shu ibora bilan ochiladi.',
                'Keraksiz boʻlsa, «Eʼtiborsiz qoldirish» tugmasini bosing.',
            ],
            'note' => 'Iboradagi # belgisi shaxsiy maʼlumot (telefon, karta raqami, pasport, pochta, havola) saqlashdan oldin yashirilganini bildiradi. Bunday qismlar hech qachon saqlanmaydi.',
        ],
        'console' => [
            'title' => 'Sinov konsoli',
            'steps' => [
                '«Chat-bot → Sinov konsoli» boʻlimini oching, mijoz iborasini yozing, til va kanalni tanlab «Tekshirish» tugmasini bosing.',
                'Ibora qidiruvda qanday koʻrinishi, qaysi qoida gʻalaba qozonganini va har bir kalit soʻzning ballini koʻrasiz.',
                'Pastda mijoz oladigan javob va tugmalar koʻrsatiladi.',
                'Hech narsa mos kelmasa, umumiy javob koʻrsatiladi.',
            ],
            'note' => 'Konsol hech narsa yubormaydi va saqlamaydi. Buyurtmani tekshiradigan, soʻrov boshlaydigan, mahsulot qidiradigan yoki menejerni chaqiradigan qoidalar uchun faqat «bajariladi» deb yoziladi.',
        ],
        'answers' => [
            'title' => 'Javoblar, tugmalar va havolalar',
            'steps' => [
                'Javob — oddiy matn, har tilda 1000 belgigacha. HTML va Markdown ishlamaydi; qatorlar saqlanadi.',
                'Tugma matni 40 belgigacha; qoidada 8 tagacha tugma boʻlishi mumkin. Tugma boshqa qoidaga, sayt sahifasiga yoki (faqat Admin) ruxsat etilgan tashqi https manzilga olib boradi.',
                'Xizmat haqida yozing: mutaxassis tanlovi, oʻrnatish va sozlash, kafolat, oʻz servis markazi, qoʻllab-quvvatlash, yetkazib berish. Narx bilan sotmang.',
                'Matn narx yoki maxsus takliflar bilan jalb qilsa, saqlashda rad etiladi: uni qayta yozing.',
            ],
        ],
        'stats' => [
            'title' => 'Statistika',
            'steps' => [
                'Davrni (7, 30 yoki 90 kun) va kerak boʻlsa kanalni tanlang.',
                '«Qoida boʻyicha javob» — bot qancha javob topdi. «Tushunmadi» — qoidasiz iboralar. «Menejerga uzatildi» — odam soʻragan mijozlar. Qolgan kartochkalar: qoldirilgan soʻrovlar, topilgan va topilmagan holat, natijasiz qidiruv.',
                '«Tushunmadi» koʻp boʻlsa, «Oʻrgatish» boʻlimini oching va kalit soʻzlar qoʻshing.',
                'Bir mavzu boʻyicha uzatishlar koʻp boʻlsa, shu mavzuga yaxshiroq javob yozing.',
            ],
        ],
        'settings' => [
            'title' => 'Sozlamalar',
            'steps' => [
                'Faqat Admin: Telegram botni va sayt chatini yoqadi yoki oʻchiradi. Maxfiylik sahifasi nashr etilib tekshirilmaguncha yoqish rad etiladi.',
                'Kontent menejer matnlarni (salomlashish, tushunilmagan savol, ish vaqtidan keyin, rozilik qatori), ish vaqtini, uzatish manzilini va vidjet sozlamalarini tahrirlaydi.',
                'Uzatish manzili suhbat savdoga, servisga yoki istalgan xodimga ketishini belgilaydi. «Botga qaytish» — jimlik vaqti (odatda 8 soat).',
                'Pastdagi cheklovlar faqat koʻrish uchun.',
            ],
            'note' => 'Har bir oʻzgarish sozlamalar tarixiga yoziladi (kim, qachon, qaysi sozlama; matn hech qachon).',
        ],
        'inbox' => [
            'title' => 'Xodimlar uchun chatlar qutisi',
            'steps' => [
                '«Chat-bot → Chatlar» boʻlimini oching: oʻqilmagan suhbatlar tepada.',
                'Suhbatni oching va oʻzingizga oling, yoki u ruxsati bor har qanday menejer uchun biriktirilmagan holda qoladi.',
                'Javob yozing: yuborilgach suhbat menejer rejimiga oʻtadi va bot jim turadi.',
                'Mijoz taklif yoki servis xohlasa, suhbatni soʻrovga aylantiring. Tugagach suhbatni yoping (yopilgan sayt chati tugaydi).',
                'Haqorat qiluvchilarni bloklang: ularning xabarlari kelmay qoʻyadi.',
            ],
        ],
    ],
];
