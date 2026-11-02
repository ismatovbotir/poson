<?php

namespace App\Console\Commands;

use App\Support\Phone;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only pre-deploy check (plan 14 v2, TG-31): counts stored customer,
 * contact, lead and order phones that Phone::normalizeAny() would change.
 * Expected: 0 everywhere. Prints counts and ids only, never the numbers.
 */
#[Signature('phones:check-normalized {--limit=20 : Max ids listed per table}')]
#[Description('Read-only: count stored phones where normalizeAny(phone) differs from the stored value (expect 0)')]
class CheckNormalizedPhones extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Read-only pre-deploy check: counts stored customer, contact, lead and order phones that the phone normaliser would still change. Expected result is 0 everywhere.
Run it before deploying a phone-format change. It prints counts and row ids only, never phone numbers, and changes no data.
Manual only, not scheduled.';

    private const TABLES = ['customers', 'contacts', 'leads', 'orders'];

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $total = 0;

        foreach (self::TABLES as $table) {
            $count = 0;
            $ids = [];

            DB::table($table)->whereNotNull('phone')->where('phone', '!=', '')->select(['id', 'phone'])->orderBy('id')
                ->chunkById(500, function ($rows) use (&$count, &$ids, $limit): void {
                    foreach ($rows as $row) {
                        if (Phone::normalizeAny($row->phone) !== $row->phone) {
                            $count++;

                            if (count($ids) < $limit) {
                                $ids[] = $row->id;
                            }
                        }
                    }
                });

            $total += $count;
            $this->line(sprintf('%-10s %d%s', $table, $count, $ids === [] ? '' : '  ids: '.implode(', ', $ids)));
        }

        $total === 0
            ? $this->components->info('All stored phones are already normalized.')
            : $this->components->warn("{$total} stored phone(s) would change; review before deploy.");

        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }
}
