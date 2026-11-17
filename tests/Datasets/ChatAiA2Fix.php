<?php

/**
 * Plan 33 A2 fix round (§19 H-1, M-1 – M-4, Low): the company's own
 * payment-method sentences that must be delivered, and the strings the
 * review found passing that must be blocked.
 */

/*
| H-1 the company's own payment-method text: prompt rule 11, seed FAQ
| (faq.php), rule answers (chatbot_defaults.php), delivery page (pages.php)
*/

dataset('a2fix own payment methods', [
    'prompt rule 11' => ['Online payment is only via UzQR (also cash and bank transfer).'],
    'prompt rule 11 en' => ['Online payment is only via UzQR; you can also pay in cash or by bank transfer.'],
    'prompt rule 11 uz' => ['Onlayn toʻlov faqat UzQR orqali; naqd pul yoki bank oʻtkazmasi ham mumkin.'],
    'prompt rule 11 ru' => ['Онлайн-оплата только через UzQR, также наличными или банковским переводом.'],
    'faq uz' => ['Hisob-varaq asosida bank oʻtkazmasi, naqd pul, terminal orqali bank kartasi hamda UzQR orqali toʻlash mumkin. Kompaniyalar uchun hisob-varaq rekvizitlaringiz asosida tayyorlanadi. Buyurtmangiz uchun qulay usulni menejer bilan aniqlashtiring.'],
    'faq ru' => ['Можно оплатить безналичным переводом по счёту, наличными, банковской картой через терминал или по UzQR. Для компаний счёт выставляется на ваши реквизиты. Удобный способ для вашего заказа уточните у менеджера.'],
    'faq en' => ['You can pay by bank transfer against an invoice, in cash, by bank card at a card terminal or with UzQR. Companies receive an invoice made out to their details. Check the most convenient method for your order with a manager.'],
    'faq b2b uz' => ['Ha. Saytda kompaniya rekvizitlari bilan tijorat taklifi soʻrovini yuboring, biz taklif va toʻlov uchun hisob tayyorlaymiz. Savatcha orqali buyurtmalarni UzQR, naqd pul yoki bank oʻtkazmasi bilan ham toʻlash mumkin.'],
    'faq b2b ru' => ['Да. Отправьте на сайте запрос коммерческого предложения с реквизитами компании, и мы подготовим предложение и счёт на оплату. Заказы через корзину также можно оплатить через UzQR, наличными или банковским переводом.'],
    'faq b2b en' => ['Yes. Send a quote request on the website with your company details, and we will prepare a commercial offer and an invoice for payment. Cart orders can also be paid through UzQR, in cash or by bank transfer.'],
    'rule uz' => ['Toʻlov usullari: hisob-faktura asosida bank orqali oʻtkazma, naqd pul, karta terminali va UzQR. Tashkilotlar uchun hisob-faktura beramiz.'],
    'rule ru' => ['Способы оплаты: банковский перевод по счёту, наличные, карточный терминал и UzQR. Для организаций выставляем счёт.'],
    'rule en' => ['Payment methods: bank transfer on an invoice, cash, card terminal and UzQR. We issue invoices for companies.'],
    'delivery page uz' => ['Qoʻqon boʻylab bepul yetkazib berish, Oʻzbekiston boʻylab kuryer, ofisdan olib ketish. Toʻlov: bank oʻtkazmasi, naqd, karta terminali va UzQR.'],
    'delivery page ru' => ['Бесплатная доставка по Коканду, курьером по всему Узбекистану, самовывоз из офиса. Оплата: перечисление по счёту, наличные, терминал и UzQR.'],
    'delivery page en' => ['Free delivery within Kokand, courier delivery across Uzbekistan, pickup from our office. Payment by bank transfer, cash, card terminal or UzQR.'],
    'pul oʻtkazish yoʻli' => ['Naqd pul yoki pul oʻtkazish yoʻli bilan toʻlashingiz mumkin.'],
    'by transfer' => ['Pay in cash or by transfer.'],
    'uzqr cash bank transfer' => ['Payment by UzQR, cash or bank transfer.'],
]);

dataset('a2fix method noun aimed at a card or person', [
    'bank transfer to the manager card' => ['Make a bank transfer to the manager’s card.'],
    'menejerga bank oʻtkazmasi' => ['Menejerga bank oʻtkazmasi qiling.'],
    'банковский перевод менеджеру' => ['Сделайте банковский перевод менеджеру.'],
]);

/*
| M-1 submission claims and contact questions (A2-S3)
*/

dataset('a2fix submission claims', [
    'yetkazildi' => ['Arizangiz menejerga yetkazildi'],
    'запрос передан' => ['Запрос передан менеджеру'],
    'passed your request' => ['I passed your request to the manager'],
    'notified a manager' => ['I’ve notified a manager'],
    'now with our manager' => ['Your request is now with our manager'],
    'zayavka prinyata' => ['Zayavka prinyata'],
    'buyurtma qabul qilindi' => ['Buyurtmangiz qabul qilindi'],
    'what is your phone' => ['What is your phone number?'],
    'may i have your phone' => ['May I have your phone number?'],
    'ваш номер пожалуйста' => ['Ваш номер, пожалуйста'],
    'raqamingiz qanday' => ['Raqamingiz qanday?'],
    'raqamni qoldiring' => ['Raqamni qoldiring'],
    'iltimos raqam qoldiring' => ['Iltimos, raqam qoldiring'],
    'оставьте номер' => ['Оставьте номер'],
    'напишите номер' => ['Напишите номер'],
    'leave a number' => ['Leave a number'],
]);

dataset('a2fix submission false positives', [
    'order is placed via the cart' => ['Order is placed on the website via the cart.'],
    'after your order is placed' => ['After your order is placed, a manager calls you.'],
    'order number' => ['Buyurtma raqami O-2026-0001.'],
    'qabul qilingandan keyin' => ['Buyurtmangiz qabul qilingandan keyin menejer bogʻlanadi.'],
    'заявка принимается' => ['Заявка принимается на сайте круглосуточно.'],
    'model raqamini yozing' => ['Model raqamini yozing'],
    'write the model number' => ['Write the model number'],
    'seriya raqamini yuboring' => ['Seriya raqamini yuboring.'],
    'номер модели' => ['Напишите номер модели принтера.'],
    'murojaat qiling' => ['Menejerga murojaat qiling, u hisob-faktura tayyorlaydi.'],
]);

/*
| M-2 pay verbs aimed at a person, hand-over verbs (L4)
*/

dataset('a2fix pay a person', [
    'menejerga toʻlang' => ['Menejerga toʻlang'],
    'заплатите менеджеру' => ['Заплатите менеджеру'],
    'оплатите менеджеру' => ['Оплатите менеджеру напрямую'],
    'pay the manager' => ['Pay the manager directly'],
    'pay our manager in cash' => ['Pay our manager in cash'],
    'shaxsiy kartasiga qiling' => ['Toʻlovni menejerimizning shaxsiy kartasiga qiling'],
    'kartasiga tashlang' => ['Pulni menejerimizning kartasiga tashlang'],
    'menejerimizga tashlang' => ['Pulni menejerimizga tashlang'],
    'menejerimizga bering' => ['Pulni menejerimizga bering'],
    'xodimimizga bering' => ['Pulni xodimimizga bering'],
    'деньги передайте курьеру' => ['Деньги передайте курьеру лично'],
    'hand the cash' => ['Hand the cash to our manager'],
    'give the money' => ['Give the money to the courier'],
    'translit perevedite' => ['Oplatu perevedite menedzheru'],
]);

/*
| M-3 Click with Uzbek suffixes and the klik spelling (A2-S9)
*/

dataset('a2fix click suffixes', [
    'clickda' => ['Clickda toʻlang'],
    'click apostrophe da' => ['Click\'da to\'lang'],
    'klik orqali' => ['Klik orqali toʻlang'],
    'klik ilovasi' => ['Klik ilovasi'],
    'paynet' => ['Paynet orqali toʻlang'],
]);

/*
| M-4 VAT next to promo words (A2-S4, A2-S5)
*/

dataset('a2fix vat promo', [
    'qqs bonus' => ['QQS 12% bonus'],
    'vat cashback' => ['VAT 12% cashback'],
    'qqs keshbek' => ['QQS 12% keshbek'],
    'qqs aksiya' => ['12% QQS aksiya'],
    'ндс подарок' => ['НДС 12% в подарок'],
    'we pay the vat' => ['12% QQS ni biz toʻlaymiz'],
    'decimal point before 12%' => ['Save on the 3.5 mm ribbon with 12% QQS'],
]);

/*
| Low: spaced emoji in a card, warranty cards
*/

dataset('a2fix warranty cards', [
    'garantiya kartasi' => ['Garantiya kartasini yuboring'],
    'гарантийную карту' => ['Отправьте гарантийную карту'],
    'kafolat kartasi' => ['Kafolat kartasini yuboring'],
    'warranty card' => ['Send the warranty card photo'],
]);

/*
| §20 M-5 – M-7: method nouns aimed at a card, cash handed to a person,
| cash on delivery to the courier only
*/

dataset('a2fix20 blocked', [
    'pul oʻtkazish yoʻli menejer kartasi' => ['Pul oʻtkazish yoʻli: menejer kartasi'],
    'bank transfer arrow manager card' => ['Bank transfer → manager card'],
    'personal account' => ['Bank transfer to Aziz’s personal account'],
    'my uzcard' => ['Bank transfer to my Uzcard'],
    'humo' => ['Bank transfer to our manager’s Humo'],
    'line break uz' => ["Bank oʻtkazmasi\nmenejer kartasiga"],
    'line break ru' => ["Банковский перевод:\nна карту менеджера"],
    'shaxsiy kartaga' => ['Bank oʻtkazmasini shaxsiy kartaga qiling'],
    'передайте наличные' => ['Передайте наличные менеджеру'],
    'naqd pulni menejerimizga' => ['Naqd pulni menejerimizga bering'],
    'pay the manager in cash' => ['Pay the manager in cash'],
    'courier to his card' => ['Pay the courier in cash to his card'],
    'kuryer kartasiga' => ['Kuryerga naqd toʻlaysiz, kuryer kartasiga ham boʻladi'],
    'qolganini menejer kartasiga' => ['Kuryerga naqd toʻlang, qolganini menejer kartasiga oʻtkazing'],
    'yoki menejerga toʻlang' => ['Kuryerga naqd toʻlaysiz yoki menejerga toʻlang'],
    'or pay the manager' => ['Pay the courier in cash or pay the manager directly'],
    'kuryerga toʻlang' => ['Kuryerga toʻlang'],
    'pay the courier' => ['Pay the courier'],
    'menejerga naqd' => ['Menejerga naqd toʻlaysiz'],
    'pay cash to the manager' => ['Pay cash to the manager'],
]);

dataset('a2fix20 delivered', [
    'kuryerga naqd toʻlaysiz' => ['Kuryerga naqd toʻlaysiz'],
    'pay the courier in cash on delivery' => ['Pay the courier in cash on delivery'],
    'yetkazib berishda naqd' => ['Yetkazib berishda naqd pul bilan toʻlaysiz'],
    'наличными курьеру' => ['Оплата наличными курьеру при получении'],
    'cash to the courier' => ['Cash to the courier on delivery'],
    'method line then courier uz' => ["Toʻlov: bank oʻtkazmasi\nKuryerga naqd toʻlaysiz"],
    'method line then courier en' => ["Payment: bank transfer\nPay the courier in cash on delivery"],
    'bank kartasi via terminal' => ['Hisob-varaq asosida bank oʻtkazmasi, naqd pul, terminal orqali bank kartasi hamda UzQR orqali toʻlash mumkin.'],
    'наличными или переводом' => ['Можно оплатить наличными или банковским переводом.'],
]);
