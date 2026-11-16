<?php

namespace App\Support\Content\AiFill;

/**
 * The prompt lines every Poson copy prompt shares (plan 32 §2.8, PW-25):
 * the company line, the brand rules with the banned wording, the "no
 * prices" rule and the Uzbek apostrophe and terminology rules. Used by the
 * Plan 29 paste prompt (AiPromptBuilder, output byte-identical, snapshot
 * test) and the wizard's write prompt. Text only.
 */
final class AiPromptRules
{
    /**
     * Wording the brand rule forbids (CLAUDE.md), listed for the AI to avoid.
     *
     * @var array<string, list<string>>
     */
    public const BANNED = [
        'English' => ['cheap', 'cheapest', 'low price', 'discount', 'sale', 'promo'],
        'Russian' => ['дешевле', 'дёшево', 'цена ниже', 'скидки', 'акции'],
        'Uzbek' => ['arzon', 'chegirma'],
    ];

    /**
     * en / ru / uz names of the usual Auto-ID words.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const TERMS = [
        ['Barcode scanner', 'Сканер штрих-кода', 'Shtrix-kod skaneri'],
        ['Label printer', 'Принтер этикеток', 'Yorliq printeri'],
        ['Receipt printer', 'Чековый принтер', 'Chek printeri'],
        ['Mobile computer', 'Терминал сбора данных (ТСД)', 'Maʼlumot yigʻish terminali (TSD)'],
        ['Ribbon', 'Риббон', 'Ribbon'],
        ['Labels', 'Этикетки', 'Yorliqlar'],
        ['Quote request', 'Запрос коммерческого предложения', 'Tijorat taklifi soʻrovi'],
        ['Service / repair', 'Сервис / ремонт', 'Servis / taʼmirlash'],
    ];

    public const COMPANY = 'Poson, a company in Uzbekistan that sells and services Auto-ID products (barcode scanners, label and receipt printers, mobile computers, RFID and POS equipment, labels, ribbons, receipt paper, software, and device services). Customers are mostly businesses (B2B).';

    /**
     * "BRAND RULES:" and its lines.
     *
     * @return list<string>
     */
    public static function brandLines(): array
    {
        return [
            'BRAND RULES:',
            '- Poson sells on service quality, never on low price: expert selection, setup and integration, official warranty, its own service centre, fast support, reliable stock and delivery.',
            '- Never mention low prices, discounts or promotions, never use percent-off badges or old/crossed-out prices. Do not use these words or their forms: '.self::bannedList().'.',
            '- Do not state prices at all.',
        ];
    }

    /**
     * "LANGUAGE:" and its lines (Uzbek apostrophes, brand names, terms).
     *
     * @return list<string>
     */
    public static function languageLines(): array
    {
        return [
            'LANGUAGE:',
            '- Uzbek is written in Latin script. Use ʻ (U+02BB) in oʻ and gʻ (oʻzbek, gʻoya) and ʼ (U+02BC) as the tutuq belgisi (maʼlumot, taʼminot, eʼlon). Never use the plain apostrophe or typographic quotes inside Uzbek words.',
            '- Keep brand and model names as they are. Use these terms:',
            ...array_map(fn (array $term): string => "  - {$term[0]} = {$term[1]} = {$term[2]}", self::TERMS),
        ];
    }

    public static function bannedList(): string
    {
        $parts = [];

        foreach (self::BANNED as $language => $words) {
            $parts[] = "{$language}: ".implode(', ', array_map(fn (string $word): string => "\"{$word}\"", $words));
        }

        return implode('; ', $parts);
    }
}
