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

    'buttons' => [
        'handoff' => 'Menejer bilan bogʻlanish',
        'menu' => 'Menyu',
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
];
