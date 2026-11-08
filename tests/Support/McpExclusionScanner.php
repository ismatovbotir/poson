<?php

namespace Tests\Support;

/**
 * The text scan behind TG-36 (MCP must not touch Telegram, CRM or personal
 * data). One definition of the needles and the exact-phrase exceptions, used
 * by tests/Feature/Mcp/McpTelegramExclusionTest.php (real files) and
 * tests/Feature/Security/ForeignPhoneAbuseTest.php (fixtures), so the two
 * can't drift.
 */
final class McpExclusionScanner
{
    /** The only MCP-side files that may use Lead, Quote or Order data (aggregates only). */
    public const AGGREGATE_FILES = [
        'app/Mcp/Tools/Stats/GetSalesStats.php',
        'app/Support/Dashboard/BusinessMetrics.php',
        'app/Support/Orders/OrderMetrics.php',
    ];

    public const CRM_MODELS = ['Lead', 'Quote', 'Order', 'OrderPayment', 'OrderItem', 'LeadItem', 'QuoteItem', 'UzqrTransaction', 'Repair', 'ChatUnmatched'];

    /**
     * Exact phrases that are not CRM data, per file (repo-relative path => phrases).
     *
     * @return array<string, list<string>>
     */
    public static function allowedPhrases(): array
    {
        return [
            // Static-page description that lists the "contacts" page slug.
            'app/Mcp/Tools/Content/ListPages.php' => ['(about, contacts, delivery, warranty, legal and custom pages)'],
            // The company's own public contact data (settings, JSON-LD, llms.txt), never customer data.
            'app/Support/CompanySettings.php' => ["\$this->memo('email'", "\$this->get('email')", "'inn' => SettingsFormat::match(\$legal['inn'] ?? null, SettingsFormat::INN)"],
            'app/Support/Site/StructuredData.php' => ['$this->company->phone()', '$this->company->email()', "\$legal['inn']", "\$data['email']", "'email' =>"],
            'app/Support/Site/LlmsTxt.php' => ["\$labels['contacts'] ?? 'Contacts'", '$this->contacts($labels)', 'function contacts(', "isEntered('email')", '$this->company->email()', '$this->company->phoneDisplay()', "\$labels['email'] ?? 'Email'", "\$labels['phone'] ?? 'Phone'"],
        ];
    }

    /**
     * @return array<string, string> label => regex (ASCII only)
     */
    public static function needles(): array
    {
        return [
            'telegram_' => '/telegram_/',
            'contacts' => '/contacts/i',
            'customers' => '/customers/i',
            'leads.message' => '/leads\.message/i',
            'message key/property' => '/[\'"]message[\'"]|->message\b/i',
            'phone/email/inn/tin key or property' => '/[\'"](?:phone|email|inn|tin)[\'"]|->(?:phone|email|inn|tin)\b/i',
            'customer/contact relation' => '/->(?:customer|contact)\b|[\'"](?:customer|contact)[\'".]/i',
        ];
    }

    /**
     * Raw CRM table access (not applied to the aggregate files): a table named
     * in table() / from() / join(), or a table-qualified column such as
     * 'orders.status'. Plain array keys and permission names ('orders',
     * 'orders.viewAny') are not table access and stay allowed.
     */
    public static function tableNeedle(): string
    {
        $tables = 'leads|orders|quotes|order_items|order_payments|uzqr_transactions|lead_items|quote_items|repairs|chat_unmatched';

        return '/(?:table|from|join|joinSub)\(\s*[\'"](?:'.$tables.')[\'"]|[\'"](?:'.$tables.')\.[a-z][a-z_]*[\'"]/';
    }

    /**
     * Labels of the needles that match the code (comments already stripped).
     *
     * @return list<string>
     */
    public static function labels(string $code, ?string $path = null): array
    {
        $code = str_replace(self::allowedPhrases()[$path] ?? [], '', $code);
        $needles = self::needles();

        if ($path === null || ! in_array($path, self::AGGREGATE_FILES, true)) {
            $needles['CRM table name'] = self::tableNeedle();
        }

        return array_keys(array_filter($needles, fn (string $pattern): bool => preg_match($pattern, $code) === 1));
    }

    /**
     * Labels for a repo-relative file.
     *
     * @return list<string>
     */
    public static function labelsForFile(string $root, string $path): array
    {
        return self::labels(self::withoutComments((string) file_get_contents($root.'/'.$path)), $path);
    }

    /**
     * Source without comments, so a docblock may say "customers".
     */
    public static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
