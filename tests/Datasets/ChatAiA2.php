<?php

/**
 * Plan 33 phase A2 (§17, §18 A2-S1 – A2-S16): the adversarial and harmless
 * strings of the chat bot AI audit fixes, shared by the output filter, the
 * knowledge text and the abuse tests.
 */

/*
| A2-1 payment instructions (L4, A2-S1, A2-S2)
*/

dataset('a2 manager calls to action', [
    'enquiry, manager prepares' => ['Send us an enquiry and a manager will prepare the quote.'],
    'quote request, manager calls' => ['Send a quote request on the website, a manager will call you.'],
    'отправьте заявку' => ['Отправьте заявку, и менеджер подготовит предложение.'],
    'menejerga soʻrov' => ['Menejerga soʻrov yuboring, u hisob-faktura tayyorlaydi.'],
    'talk to a manager button' => ['Press the Talk to a manager button and send the model name.'],
    'rub test' => ['A test print on your actual labels, followed by a rub test, is the most reliable way to confirm a choice.'],
    'uz b2b paragraph' => ['Vazifangizni qisqacha yozib murojaat qoldiring yoki mahsulot sahifasida tijorat taklifi soʻrovini yuboring. Menejerimiz siz bilan bogʻlanib, taklif tayyorlaydi.'],
]);

dataset('a2 invoice and warranty card', [
    'счёт на оплату' => ['Мы отправим счёт на оплату.'],
    'invoice for payment' => ['We will send the invoice for payment.'],
    'kafolat kartasi' => ['Kafolat kartasini va seriya raqamini yuboring.'],
    'saytda toʻlang' => ['Toʻlov kartasi bilan saytda toʻlang'],
    'картой или наличными' => ['Оплата картой или наличными'],
    'card or cash' => ['Pay by card or cash on delivery'],
]);

dataset('a2 payment instructions', [
    // §17.2 boundary rows.
    'manager card en' => ['Send the payment to the manager\'s card'],
    'manager card ru' => ['Отправьте оплату на карту менеджера'],
    'kartaga yuboring' => ['Kartaga yuboring'],
    'money to manager' => ['Send the money to the manager'],
    'pulni menejerga' => ['Pulni menejerga yuboring'],
    'деньги менеджеру' => ['Деньги отправьте менеджеру'],
    // A2-S1: blocked before A2, must stay blocked.
    'transfer it' => ['Transfer it to the manager'],
    'wire it' => ['Wire it to our manager directly'],
    'переведите менеджеру' => ['Переведите менеджеру'],
    'скиньте менеджеру' => ['Скиньте менеджеру'],
    'menejerga oʻtkazing' => ['Menejerga oʻtkazing'],
    'pullaringizni' => ['Pullaringizni menejerga oʻtkazing'],
    'mablagʻingizni' => ['Mablagʻingizni menejerga yuboring'],
    'toʻlov summasini' => ['Toʻlov summasini menejerga oʻtkazing'],
    'soʻmni menejerga' => ['ZD421 uchun 4 500 000 soʻmni menejerga oʻtkazing'],
    'uz cyrillic пулни' => ['пулни менежерга юборинг'],
    'uz cyrillic картага' => ['Картага пул ўтказинг'],
    // A2-S2: pay verbs with a directional target.
    'kartaga toʻlang' => ['Kartaga toʻlang'],
    'оплатите на карту' => ['Оплатите на карту менеджера'],
    'pay to the card' => ['Pay to the manager\'s card'],
]);

/*
| A2-S3 fake submissions and personal data, A2-S9 UzQR only
*/

dataset('a2 submission claims', [
    'soʻrov yuborildi' => ['Soʻrovingiz menejerga yuborildi'],
    'qabul qilindi' => ['Soʻrovingiz qabul qilindi'],
    'заявка оформлена' => ['Заявка оформлена, менеджер свяжется'],
    'request has been sent' => ['Your request has been sent to a manager'],
    'passed it on' => ['I have passed it to our team.'],
    'оставьте номер' => ['Оставьте ваш номер телефона'],
    'telefon raqamingizni' => ['Telefon raqamingizni yozing'],
    'leave your phone' => ['Please leave your phone number here.'],
]);

dataset('a2 other payment services', [
    'payme yoki click' => ['Payme yoki Click orqali toʻlashingiz mumkin'],
    'через click' => ['Оплатите через Click'],
    'pay with payme' => ['Pay with Payme'],
    'paycom' => ['Paycom ilovasi orqali'],
]);

/*
| A2-S14 obfuscation
*/

dataset('a2 obfuscations', [
    'cyrillic а in kartaga' => ['kаrtaga yuboring', 'contact'],
    'spaced perevod' => ['p e r e v o d на карту', 'contact'],
    'spaced money' => ['Send the m o n e y to the manager', 'contact'],
    'emoji in pulni' => ['Pul💰ni menejerga yuboring', 'contact'],
    'emoji card number' => ['8600💳1234💳5678💳9012', 'card'],
    'dotted chegirma' => ['ch.e.g.i.r.m.a', 'brand'],
    'card number to transfer' => ['8600-1234-5678-9012 ga oʻtkazing', 'card'],
    'card number with yuboring' => ['Kartaga yuboring 8600 1234 5678 9012', 'card'],
]);

/*
| A2-4 VAT (A2-S4, A2-S5)
*/

dataset('a2 vat allowed', [
    'qqs bilan' => ['Narxlar 12% QQS bilan'],
    'space ндс' => ['12 % НДС'],
    'qqs brackets' => ['QQS (12%)'],
    'vat included' => ['Prices include 12% VAT.'],
]);

dataset('a2 vat blocked', [
    'minus' => ['QQS -12%'],
    'unicode minus' => ['QQS −12%'],
    'vat off' => ['12% VAT off'],
    'other rate' => ['Narxlar 30% QQS'],
    'save vat' => ['Save 12% VAT with us'],
    'вернём' => ['НДС 12% вернём'],
    'qaytariladi' => ['12% QQS qaytariladi'],
    'vat and chegirma' => ['12% VAT, 20% chegirma'],
    'twenty vat' => ['20% VAT'],
    'bare minus rate' => ['−12%'],
    'chegirma' => ['12% chegirma'],
]);

/*
| A2-4 amounts (A2-S13) and the rub test
*/

dataset('a2 amounts', [
    'dash zero' => ['Uskunaning qiymati 45000-0'],
    'range of amounts' => ['Tovar 45000-50000'],
    'iso then price' => ['ISO 9001, narxi 450 000 soʻm'],
    'grouped range' => ['1 200–1 500 000'],
    'priced range' => ['narxi 500–900'],
    'spaced em dash' => ['R110 — 9000'],
    '500 rub' => ['500 rub'],
    'rub 500' => ['rub 500'],
    'ruble sign' => ['₽500'],
]);

dataset('a2 frequencies and standards', [
    'uhf' => ['UHF 860–960 MHz'],
    'lf' => ['LF 125–134 kHz'],
    'iso iec' => ['ISO/IEC 18000-63'],
    'ieee' => ['IEEE 802.11 ac'],
    'temperature' => ['Ishlash harorati 0–40 °C'],
]);

/*
| A2-4 cheap wording (B4) and price levels (A2-S15)
*/

dataset('a2 cheap wording', [
    'недорогой' => ['Недорогой принтер для небольшого магазина'],
    'бюджетный' => ['Бюджетный сканер для кассы'],
    'ниже рынка' => ['Цены ниже рынка'],
    'предложение месяца' => ['Лучшее предложение месяца'],
    'hamyonbop' => ['Hamyonbop yechim'],
    'tejamkor' => ['Tejamkor variant'],
    'maxsus narx' => ['Maxsus narx hamkorlarga'],
    'special price' => ['Special price for partners'],
    'save money' => ['Save money on labels'],
    'cost-effective' => ['Cost-effective solution'],
    'dealer prices' => ['Dealer prices on request'],
    'оптовые цены' => ['Оптовые цены для дилеров'],
    'wholesale pricing' => ['Wholesale pricing on request'],
    'оптом дилерским' => ['Оптом по дилерским ценам'],
    'optom narxlar' => ['Optom narxlar'],
]);

dataset('a2 brand false positives', [
    'бюджетные организации' => ['Работаем с бюджетными организациями'],
    'energiya tejamkor' => ['Energiya tejamkor printer'],
    'save on memory' => ['Save on the device memory'],
    'click the button' => ['Click the button below'],
]);
