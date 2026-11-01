<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * How the launch demo content is recognised (plan 15 A6), so it can be
 * removed (App\Actions\Maintenance\RemoveDemoContent, `php artisan
 * demo:remove`) before real products go in:
 *
 * - products: the frozen LEGACY_PRODUCT_SKUS (the removed demo products seeder; plan 16);
 * - news: the slugs DemoNewsSeeder creates (kind news);
 * - banners: the uz title + link pairs DemoBannersSeeder creates (banners have no slug).
 *
 * Matching alone is not enough (those SKUs are real products Poson sells):
 * the seeders record the IDs they created in the non-secret setting
 * `launch_demo_content` (remember()), and only those IDs are ever removable,
 * and only while still matching and unedited after seeded_at. A removal drops
 * the record and leaves the `launch_demo_removed` marker, so a seeder re-run
 * never recreates the demo content.
 *
 * Keep these lists in sync with the seeders (LaunchSeederTest checks that a
 * removal after a launch seed leaves none of them behind).
 */
final class DemoContent
{
    /**
     * @var list<string>
     */
    public const NEWS_SLUGS = [
        'servis-markazi-ish-vaqti',
        '1c-bilan-integratsiya',
        'yangi-yorliq-printerlari',
    ];

    /**
     * @var list<array{title_uz: string, link: string}>
     */
    public const BANNERS = [
        ['title_uz' => 'Uskunani tanlash, sozlash va servis bir joyda', 'link' => '/catalog'],
        ['title_uz' => 'Rasmiy kafolat va oʻz servis markazimiz', 'link' => '/page/warranty-service'],
    ];

    /**
     * The 39 legacy demo product SKUs, frozen (plan 16): the demo data file is gone, and
     * `demo:remove` must never be able to target a real product SKU.
     *
     * @var list<string>
     */
    public const LEGACY_PRODUCT_SKUS = [
        'ZEB-DS2208-SR',
        'HON-1470G',
        'HON-1472G',
        'ZEB-DS2278-SR',
        'HON-7190G',
        'ZEB-DS9308-SR',
        'ZEB-ZD421T-203',
        'TSC-TE210',
        'GDX-G500',
        'ZEB-ZT411-203',
        'ZEB-ZQ320-PLUS',
        'URV-DT50',
        'ZEB-TC22',
        'LBL-DT-58X40-ECO',
        'LBL-DT-100X150-TOP',
        'LBL-TT-58X30-PAP',
        'LBL-TT-100X50-PP',
        'RBN-WAX-110X300',
        'RBN-WR-110X450',
        'RBN-RES-110X300',
        'PPR-80X80',
        'PPR-57X40',
        'ZEB-FX9600-4',
        'ZEB-RFD40-PREM',
        'RFID-UHF-100X50',
        'SUN-V2S',
        'XPR-XPQ200-LAN',
        'XPR-XP58IIH',
        'ATL-CD410',
        'SW-MOYSKLAD',
        'SW-OX',
        'SW-BILLZ',
        'SW-ALIPOS',
        'SW-HIPPO',
        'SW-POSPOINT',
        'SW-RAHMAT',
        'SRV-LBLPRN-SETUP',
        'SRV-SCN-PROG',
        'SRV-TSD-DIAG',
    ];

    /**
     * Upper-cased SKUs of the legacy demo products.
     *
     * @return list<string>
     */
    public static function productSkus(): array
    {
        return self::LEGACY_PRODUCT_SKUS;
    }

    public const KEY = 'launch_demo_content';

    public const REMOVED_KEY = 'launch_demo_removed';

    /**
     * Minutes after seeded_at in which a write still counts as the seeder's own.
     */
    public const GRACE_MINUTES = 5;

    /**
     * The recorded IDs: product_ids, news_ids, banner_ids and seeded_at; null without a record.
     *
     * @return array{product_ids: list<int>, news_ids: list<int>, banner_ids: list<int>, seeded_at: string}|null
     */
    public static function record(): ?array
    {
        $value = Setting::query()->where('key', self::KEY)->value('value');

        if (! is_array($value)) {
            return null;
        }

        return [
            'product_ids' => array_values(array_map('intval', $value['product_ids'] ?? [])),
            'news_ids' => array_values(array_map('intval', $value['news_ids'] ?? [])),
            'banner_ids' => array_values(array_map('intval', $value['banner_ids'] ?? [])),
            'seeded_at' => (string) ($value['seeded_at'] ?? ''),
        ];
    }

    /**
     * Merge IDs created by a seeder into the record (never overwrites the other kinds).
     *
     * @param  'product_ids'|'news_ids'|'banner_ids'  $kind
     * @param  list<int>  $ids
     */
    public static function remember(string $kind, array $ids): void
    {
        $record = self::record() ?? ['product_ids' => [], 'news_ids' => [], 'banner_ids' => [], 'seeded_at' => now()->toIso8601String()];
        $record[$kind] = array_values(array_unique([...$record[$kind], ...array_map('intval', $ids)]));
        $record['seeded_at'] = $record['seeded_at'] !== '' ? $record['seeded_at'] : now()->toIso8601String();

        Setting::query()->updateOrCreate(['key' => self::KEY], ['group' => 'system', 'value' => $record]);
    }

    /**
     * The cut-off: rows updated after it were edited by a person.
     */
    public static function editedAfter(): ?CarbonImmutable
    {
        $seededAt = self::record()['seeded_at'] ?? '';

        return $seededAt === '' ? null : CarbonImmutable::parse($seededAt)->addMinutes(self::GRACE_MINUTES);
    }

    /**
     * Drop the record after a removal (kept when edited rows were skipped, so --include-edited can still remove them) and leave the marker that blocks re-seeding.
     */
    public static function markRemoved(bool $dropRecord = true): void
    {
        if ($dropRecord) {
            Setting::query()->where('key', self::KEY)->get()->each->delete();
        }

        Setting::query()->updateOrCreate(['key' => self::REMOVED_KEY], ['group' => 'system', 'value' => ['removed_at' => now()->toIso8601String()]]);
    }

    public static function wasRemoved(): bool
    {
        return Setting::query()->where('key', self::REMOVED_KEY)->exists();
    }
}
