<?php

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Support\Catalog\CategoryTree;

/**
 * Gives the seeded categories their own icons (the old seed reused scan,
 * print, term, label, rfid and pos, or left the icon empty). Idempotent and
 * safe on production: per slug it only writes `icon` and only while the
 * current value is empty or still the old seeded default, so an icon an admin
 * chose is never touched. Called from LaunchSeeder (CatalogSeeder is
 * create-only and never updates existing rows).
 */
class ApplyCategoryIcons
{
    /**
     * Slug => [old seeded icon (null = empty), new icon key from Category::ICONS].
     *
     * @var array<string, array{0: string|null, 1: string}>
     */
    public const MAP = [
        'scanners' => ['scan', 'cat-handheld-scanner'],
        'handheld-scanners' => ['scan', 'cat-handheld-scanner'],
        'wireless-scanners' => ['scan', 'cat-wireless-scanner'],
        'presentation-scanners' => ['scan', 'cat-presentation-scanner'],
        'embedded-scanners' => ['scan', 'cat-embedded-scanner'],
        'label-printers' => ['print', 'cat-desktop-label-printer'],
        'desktop-label-printers' => ['print', 'cat-desktop-label-printer'],
        'industrial-label-printers' => ['print', 'cat-industrial-label-printer'],
        'mobile-label-printers' => ['print', 'cat-mobile-label-printer'],
        'rfid-printers' => ['print', 'cat-rfid-printer'],
        'mobile-computers' => ['term', 'cat-mobile-computer'],
        'android-mobile-computers' => ['term', 'cat-mobile-computer'],
        'wearable-computers' => ['term', 'cat-wearable-computer'],
        'rfid-mobile-computers' => ['term', 'cat-rfid-mobile-computer'],
        'labels-ribbons' => ['label', 'cat-thermal-label-roll'],
        'thermal-labels' => ['label', 'cat-thermal-label-roll'],
        'thermal-transfer-labels' => ['label', 'cat-thermal-transfer-roll'],
        'ribbons' => ['label', 'cat-ribbon'],
        'receipt-paper' => ['label', 'cat-receipt-paper'],
        'textile-tapes' => ['label', 'cat-textile-tape'],
        'rfid' => ['rfid', 'cat-fixed-rfid-reader'],
        'fixed-rfid-readers' => ['rfid', 'cat-fixed-rfid-reader'],
        'handheld-rfid-readers' => ['rfid', 'cat-handheld-rfid-reader'],
        'rfid-antennas' => ['rfid', 'cat-rfid-antenna'],
        'rfid-tags' => ['rfid', 'cat-rfid-tag'],
        'pos' => ['pos', 'cat-pos-terminal'],
        'pos-terminals' => ['pos', 'cat-pos-terminal'],
        'receipt-printers' => ['print', 'cat-receipt-printer'],
        'cash-drawers' => ['pos', 'cat-cash-drawer'],
        'customer-displays' => ['pos', 'cat-customer-display'],
        'scales' => ['pos', 'cat-scale'],
        'price-checkers' => ['pos', 'cat-price-checker'],
        'software' => [null, 'cat-pos-software'],
        'pos-software' => [null, 'cat-pos-software'],
        'warehouse-software' => [null, 'cat-warehouse-software'],
        'services' => [null, 'cat-setup-integration'],
        'setup-integration' => [null, 'cat-setup-integration'],
        'repair-maintenance' => [null, 'cat-repair-maintenance'],
        'accessories-parts' => ['pos', 'cat-accessory'],
        'accessories' => ['pos', 'cat-accessory'],
        'spare-parts' => ['pos', 'cat-spare-part'],
    ];

    /**
     * @return int Number of categories changed.
     */
    public function handle(): int
    {
        $changed = 0;

        foreach (self::MAP as $slug => [$old, $new]) {
            $query = Category::query()->where('slug', $slug)->where(function ($query) use ($old): void {
                $query->whereNull('icon');

                if ($old !== null) {
                    $query->orWhere('icon', $old);
                }
            });

            $changed += $query->toBase()->update(['icon' => $new]);
        }

        if ($changed > 0) {
            CategoryTree::flush();
        }

        return $changed;
    }
}
