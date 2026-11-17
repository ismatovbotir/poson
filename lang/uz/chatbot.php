<?php

/**
 * Qoidalarga asoslangan chat-botning ichki matnlari (reja 28). Faqat oddiy matn.
 * Qoida javoblari Admin → Chat-bot boʻlimida tahrirlanadi; bu yerda — oʻzgarmas zaxira matnlar.
 */
return [
    'fallback' => 'Men yaxshi tushunmadim. Quyidagi mavzulardan birini tanlang yoki menejerga yozing.',
    'fallback_error' => 'Yordamchi hozir ishlamayapti. Iltimos, menejerga murojaat qiling.',
    'menu_title' => 'Sizni nima qiziqtiradi? Mavzuni tanlang.',
    'coming_soon' => 'Hozircha bu masalada chatda yordam bera olmayman. Iltimos, menejerga murojaat qiling.',
    'search_soon' => 'Chatda mahsulot qidirish tez orada paydo boʻladi. Menejerga murojaat qiling yoki katalogni oching.',
    'handoff' => 'Savolingizni shu yerga yozing. Menejer imkon qadar tezroq javob beradi.',
    'cancelled' => 'Bekor qilindi.',
    'not_available' => 'Bu variant mavjud emas. Menyudan mavzuni tanlang.',

    'ai' => [
        'marker' => 'SI yordamchi · aniq narx va buyurtmalar uchun menejerga murojaat qiling',
        'limited' => 'Qisqa vaqtda juda koʻp savol berdingiz. Menejer bajonidil yordam beradi: quyidagi tugmani bosing.',
    ],

    'buttons' => [
        'handoff' => 'Menejer bilan bogʻlanish',
        'menu' => 'Menyu',
        'callback' => 'Qayta qoʻngʻiroq soʻrash',
    ],

    'faq' => [
        'pick' => 'Savolni tanlang:',
        'empty' => 'Bu yerda hozircha savollar yoʻq. Iltimos, menejerga murojaat qiling.',
        'unavailable' => 'Bu javob mavjud emas. Iltimos, menejerga murojaat qiling.',
    ],

    'company' => [
        'address' => 'Manzil: :value',
        'hours' => 'Ish vaqti: :value',
        'phone' => 'Telefon: :value',
        'email' => 'Email: :value',
        'unavailable' => 'Bu maʼlumot hozircha eʼlon qilinmagan. Iltimos, menejerga murojaat qiling.',
    ],

    'days' => [
        'mo' => 'Du',
        'tu' => 'Se',
        'we' => 'Ch',
        'th' => 'Pa',
        'fr' => 'Ju',
        'sa' => 'Sh',
        'su' => 'Ya',
    ],

    'errors' => [
        'action_invalid' => 'Yaroqli amalni tanlang.',
        'key_invalid' => 'Kalit faqat kichik lotin harflari, raqamlar va pastki chiziqdan iborat boʻlishi mumkin (2–60 belgi, harf bilan boshlanadi).',
        'key_taken' => 'Bu kalit boshqa qoidada ishlatilgan.',
        'text_plain' => 'Faqat oddiy matn ishlating: "<" va boshqaruv belgilarisiz.',
        'text_too_long' => 'Matn juda uzun.',
        'answer_required' => 'Bu amal uchun oʻzbekcha javob kerak.',
        'label_required' => 'Oʻzbekcha yozuv kerak.',
        'brand' => 'Matn past narx yoki chegirmalarni reklama qilmasligi kerak.',
        'url_forbidden' => 'Tashqi havolani faqat Admin qoʻsha oladi va faqat ruxsat etilgan https manzilga.',
        'target_invalid' => 'Havola uchun ruxsat etilgan sayt sahifasini tanlang.',
        'topic_invalid' => 'Kompaniya haqida qaysi maʼlumot koʻrsatilishini tanlang.',
        'faq_invalid' => 'Faol savol-javobni tanlang.',
        'flow_invalid' => 'Yaroqli soʻrov turini tanlang.',
        'button_invalid' => 'Tugma uchun yozuv va bitta yaroqli manzil kerak.',
        'keyword_invalid' => 'Kalit soʻz boʻsh, juda uzun yoki turi yoki ogʻirligi notoʻgʻri.',
        'too_many_rules' => ':max ta qoida chegarasiga yetildi.',
        'too_many_keywords' => 'Qoidada koʻpi bilan :max ta kalit soʻz boʻlishi mumkin.',
        'too_many_buttons' => 'Qoidada koʻpi bilan :max ta tugma boʻlishi mumkin.',
        'system_rule_delete' => 'Ichki qoidani oʻchirib boʻlmaydi. Uni oʻchirib qoʻying (nofaol qiling).',
    ],

    'after_hours' => 'Hozir aloqada emasmiz. Ish vaqtida javob beramiz.',

    'search' => [
        'prompt' => 'Nima izlayotganingizni yozing: model, artikul yoki nom.',
        'found' => 'Topildi:',
        'line' => ':n. :name — :price',
        'none' => 'Hech narsa topilmadi. Boshqa soʻzlarni sinab koʻring yoki menejerdan soʻrang.',
        'limited' => 'Qidiruvlar juda koʻp. Menejerdan soʻrang yoki katalogni oching.',
        'unavailable' => 'Bu mahsulot endi mavjud emas. Menejerdan soʻrang.',
        'brand' => 'Brend: :brand',
        'price' => 'Narxi: :price',
        'price_on_request' => 'narxi soʻrov boʻyicha',
        'open' => 'Saytda ochish',
        'quote' => 'Taklif soʻrash',
    ],

    'lookup' => [
        'limited' => 'Tekshiruvlar juda koʻp. Menejerdan soʻrang.',
        'unavailable' => 'Hozir tekshirish mavjud emas. Menejerdan soʻrang.',
        'since' => 'Holat sanasi: :day',
        'status' => 'Holati: :status',
        'web' => [
            'order' => 'Buyurtma holati telefon raqamingizni yuborganingizdan keyin Telegram botimizda koʻrinadi.',
            'repair' => 'Taʼmir holati telefon raqamingizni yuborganingizdan keyin Telegram botimizda koʻrinadi.',
        ],
        'order' => [
            'none' => 'Telefon raqamingiz boʻyicha buyurtma topilmadi.',
            'not_found' => 'Telefon raqamingiz boʻyicha bunday buyurtma topilmadi. Raqamni tekshiring yoki menejerdan soʻrang.',
            'recent' => 'Oxirgi buyurtmalaringiz. Birini tanlang:',
            'title' => 'Buyurtma :number',
            'payment' => 'Toʻlov: :status',
        ],
        'repair' => [
            'none' => 'Telefon raqamingiz boʻyicha taʼmir topilmadi.',
            'not_found' => 'Telefon raqamingiz boʻyicha bunday taʼmir topilmadi. Raqamni tekshiring yoki menejerdan soʻrang.',
            'recent' => 'Oxirgi taʼmirlaringiz. Birini tanlang:',
            'title' => 'Taʼmir :number',
            'device' => 'Qurilma: :device',
            'awaiting_approval' => 'Sizning kelishuvingizni kutmoqdamiz. Menejer siz bilan bogʻlanadi.',
            'ready' => 'Qurilmangiz topshirishga tayyor.',
        ],
    ],

    'dialog' => [
        'buttons' => [
            'cancel' => 'Bekor qilish',
            'send' => 'Yuborish',
            'skip' => 'Oʻtkazib yuborish',
        ],
        'callback' => ['details' => 'Qaysi masala boʻyicha qoʻngʻiroq qilaylik? Qisqacha yozing yoki oʻtkazib yuboring.'],
        'quote' => [
            'details' => 'Qanday uskuna kerak? Model va miqdorini yozing.',
            'details_product' => ':product uchun miqdor va istaklaringizni yozing.',
        ],
        'service' => ['details' => 'Qurilma va muammoni tasvirlab bering.'],
        'required' => 'Bu bosqichni oʻtkazib boʻlmaydi.',
        'confirm' => 'Soʻrovingizni tekshiring:',
        'summary' => [
            'type' => 'Turi: :type',
            'product' => 'Mahsulot: :product',
            'details' => 'Tafsilotlar: :details',
            'phone' => 'Telefon: :phone',
        ],
        'sent' => 'Rahmat! :number raqamli soʻrovingiz qabul qilindi. Menejer siz bilan bogʻlanadi.',
        'expired' => 'Soʻrov vaqti tugadi. Qaytadan boshlang.',
        'limited' => 'Soʻrovlar juda koʻp. Menejerga murojaat qiling.',
        'refused' => 'Bu soʻrovni bu yerda qabul qila olmaymiz. Menejerga murojaat qiling.',
    ],

    // Website knowledge index (plan 33): replaces a money amount found in site text.
    'knowledge' => [
        'docs' => 'Hujjatlar',
        'open_prefix' => 'Ochish: ',
        'open_button' => 'Ochish: :title',
        'sources' => 'Manba sahifalar:',
        'amount_masked' => '(sahifaga qarang)',
    ],

    // Website chat widget (plan 28 C5b). Plain text; the brand rule applies (no price or discount wording).
    'widget' => [
        'open' => 'Chatni ochish',
        'close' => 'Chatni yopish',
        'title' => 'Poson chati',
        'subtitle' => 'Uskunalar, sozlash va servis boʻyicha savollar',
        'bot' => 'Yordamchi',
        'staff' => 'Menejer',
        'you' => 'Siz',
        'placeholder' => 'Xabar yozing…',
        'send' => 'Yuborish',
        'consent' => 'Xabar yuborib, yozishmangiz qayta ishlanishiga rozilik bildirasiz. Batafsil:',
        'consent_link' => 'maxfiylik siyosati',
        'menu' => 'Menyu',
        'end' => 'Chatni yakunlash',
        'end_confirm' => 'Bu chat yakunlansinmi? Yozishma yopiladi.',
        'ended' => 'Chat yakunlandi. Yangisini boshlash uchun xabar yozing.',
        'check_replies' => 'Javoblarni tekshirish',
        'waiting' => 'Menejer shu yerda javob beradi. Saytni koʻrishda davom etishingiz mumkin.',
        'wait_staff' => 'Iltimos, menejer javobini kuting.',
        'too_many' => 'Qisqa vaqtda juda koʻp xabar yuborildi. Biroz kuting.',
        'unavailable' => 'Chat hozir ishlamayapti. Bizga qoʻngʻiroq qiling yoki aloqa shaklidan foydalaning.',
        'error' => 'Aloqa yoʻq. Birozdan soʻng yana urinib koʻramiz.',
        'loading' => 'Chat yuklanmoqda…',
        'typing' => 'Yozmoqda…',
        'unread' => 'Yangi javob',
        'after_hours' => 'Hozir ish vaqtidan tashqari. Ish vaqtida javob beramiz.',
        'handoff' => 'Menejer bilan bogʻlanish',
        'contact_title' => 'Menejer qayta qoʻngʻiroq qilishi uchun telefon raqamingizni qoldiring (ixtiyoriy).',
        'contact_name' => 'Ism',
        'contact_phone' => 'Telefon',
        'contact_save' => 'Saqlash',
        'contact_skip' => 'Yoʻq, rahmat',
        'contact_saved' => 'Rahmat! Menejer raqamingizni koʻradi.',
        'form_intro' => 'Iltimos, quyidagi qisqa shaklni toʻldiring.',
        'form_title' => [
            'callback' => 'Qayta qoʻngʻiroq',
            'quote' => 'Tijorat taklifini soʻrash',
            'service' => 'Servis uchun ariza',
        ],
        'details' => 'Tafsilotlar',
        'details_hint' => [
            'callback' => 'Qaysi masala boʻyicha qoʻngʻiroq qilaylik? (ixtiyoriy)',
            'quote' => 'Kerakli modellar va soni',
            'service' => 'Qurilma va nosozlik',
        ],
        'name' => 'Ismingiz',
        'phone' => 'Telefon',
        'submit' => 'Arizani yuborish',
        'cancel' => 'Bekor qilish',
        'lead_sent' => 'Rahmat! Arizangiz qabul qilindi. Menejer siz bilan bogʻlanadi.',
        'lead_done' => 'Arizangiz yuborildi. Menejer siz bilan bogʻlanadi.',
        'product_unavailable' => 'Bu mahsulot endi mavjud emas. Menyudan mavzu tanlang yoki menejerga yozing.',
        'price_on_request' => 'Narxi soʻrov boʻyicha',
        'quote' => 'Tijorat taklifini soʻrash',
        'open_product' => 'Ochish',
    ],
];
