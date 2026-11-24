<?php

use App\Enums\DiskMeasureStatus as Status;
use App\Enums\DiskTarget;
use App\Support\Monitoring\FolderSizer;
use Illuminate\Support\Facades\File;

/**
 * FolderSizer (plan 37, DM-1, 2, 4, 5, 21, 22). Only temp directories, never the real tree.
 */
beforeEach(function () {
    $this->scratch = realpath(sys_get_temp_dir()).'/poson-sizer-'.bin2hex(random_bytes(6));
    $this->base = $this->scratch.'/app';
    $this->outside = $this->scratch.'/outside';
    mkdir($this->base, 0777, true);
    mkdir($this->outside, 0777, true);
});

afterEach(function () {
    foreach ([$this->base, $this->outside] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if (is_dir($entry->getPathname()) && ! is_link($entry->getPathname())) {
                @chmod($entry->getPathname(), 0777);
            }
        }
    }

    File::deleteDirectory($this->scratch);
});

/**
 * @param  array<string, int>  $files  relative path => bytes
 */
function sizerTree(string $root, array $files): void
{
    foreach ($files as $path => $bytes) {
        $full = $root.'/'.$path;
        is_dir(dirname($full)) || mkdir(dirname($full), 0777, true);
        file_put_contents($full, str_repeat('a', $bytes));
    }
}

function sizerMeasure(object $test, array $options = []): array
{
    $sizer = new FolderSizer($test->base, $options['storage'] ?? $test->base.'/storage', $options['entries'] ?? null, $options['seconds'] ?? null);
    $result = $sizer->measure($options['progress'] ?? null);

    return [$result, fn (DiskTarget $target) => $result->row($target)];
}

it('attributes files to exact top-level buckets, sums other directly and keeps total = sum of buckets', function () {
    sizerTree($this->base, [
        'storage/logs/a.log' => 100,
        'storage/logs-old/b.log' => 40,
        'storage/framework/views/v.php' => 10,
        'storage/app/public/p.webp' => 200,
        'storage/loose.txt' => 5,
        'vendor/x/y.php' => 1000,
        'public/index.php' => 7,
        'node_modules/n/index.js' => 50,
        '.git/HEAD' => 3,
        'app/Models/User.php' => 20,
        'composer.json' => 9,
        '.env' => 33,
        'vendorish/z.txt' => 11,
    ]);

    [$result, $row] = sizerMeasure($this);

    expect($row(DiskTarget::Storage)['bytes'])->toBe(355)
        ->and($row(DiskTarget::StorageLogs)['bytes'])->toBe(100)
        ->and($row(DiskTarget::StorageFrameworkViews)['bytes'])->toBe(10)
        ->and($row(DiskTarget::StorageAppPublic)['bytes'])->toBe(200)
        ->and($row(DiskTarget::Vendor)['bytes'])->toBe(1000)
        ->and($row(DiskTarget::Public)['bytes'])->toBe(7)
        ->and($row(DiskTarget::NodeModules)['bytes'])->toBe(50)
        ->and($row(DiskTarget::Git)['bytes'])->toBe(3)
        ->and($row(DiskTarget::Other)['bytes'])->toBe(20 + 9 + 33 + 11)
        ->and($row(DiskTarget::Other)['files'])->toBe(4)
        ->and($row(DiskTarget::ProjectTotal)['bytes'])->toBe(355 + 1000 + 7 + 50 + 3 + 73)
        ->and(collect(DiskTarget::buckets())->sum(fn (DiskTarget $t) => $row($t)['bytes']))->toBe($row(DiskTarget::ProjectTotal)['bytes'])
        ->and($row(DiskTarget::ProjectTotal)['status'])->toBe(Status::Ok);
});

it('reports a bucket that does not exist as missing', function () {
    sizerTree($this->base, ['storage/logs/a.log' => 1, 'app/x.php' => 1]);

    [, $row] = sizerMeasure($this);

    expect($row(DiskTarget::Vendor)['status'])->toBe(Status::Missing)
        ->and($row(DiskTarget::StorageMediaLibrary)['status'])->toBe(Status::Missing)
        ->and($row(DiskTarget::StorageLogs)['status'])->toBe(Status::Ok);
});

it('never follows symlinks: a loop, a link to an outside file or folder and public/storage add nothing', function () {
    sizerTree($this->base, ['storage/app/public/real.bin' => 100, 'public/index.php' => 1]);
    sizerTree($this->outside, ['big.bin' => 5000, 'deep/more.bin' => 5000]);
    symlink($this->base.'/storage/app/public', $this->base.'/public/storage');
    symlink($this->base.'/storage', $this->base.'/storage/loop');
    symlink($this->outside.'/big.bin', $this->base.'/storage/app/public/big-link.bin');
    symlink($this->outside, $this->base.'/vendor');
    symlink('/', $this->base.'/rootlink');
    symlink($_SERVER['HOME'] ?? '/', $this->base.'/homelink');

    [, $row] = sizerMeasure($this);

    expect($row(DiskTarget::ProjectTotal)['bytes'])->toBe(101)
        ->and($row(DiskTarget::Public)['bytes'])->toBe(1)
        ->and($row(DiskTarget::Vendor)['bytes'])->toBe(0)
        ->and($row(DiskTarget::StorageAppPublic)['bytes'])->toBe(100);
});

it('counts a storage folder symlinked outside the project once, under storage', function () {
    sizerTree($this->base, ['app/x.php' => 10]);
    sizerTree($this->outside, ['storage/logs/a.log' => 300, 'storage/app/public/p' => 200]);
    symlink($this->outside.'/storage', $this->base.'/storage');

    [, $row] = sizerMeasure($this, ['storage' => $this->base.'/storage']);

    expect($row(DiskTarget::Storage)['bytes'])->toBe(500)
        ->and($row(DiskTarget::StorageLogs)['bytes'])->toBe(300)
        ->and($row(DiskTarget::ProjectTotal)['bytes'])->toBe(510)
        ->and($row(DiskTarget::Storage)['status'])->toBe(Status::Ok);
});

it('refuses a storage folder that links to an ancestor of the project or into the project', function () {
    sizerTree($this->base, ['app/x.php' => 10]);
    sizerTree($this->outside, ['big.bin' => 9000]);
    symlink($this->scratch, $this->base.'/storage');

    [, $row] = sizerMeasure($this, ['storage' => $this->base.'/storage']);

    expect($row(DiskTarget::Storage)['status'])->toBe(Status::Unreadable)
        ->and($row(DiskTarget::Storage)['bytes'])->toBe(0)
        ->and($row(DiskTarget::ProjectTotal)['bytes'])->toBe(10)
        ->and($row(DiskTarget::ProjectTotal)['status'])->toBe(Status::Partial);

    unlink($this->base.'/storage');
    symlink($this->base.'/app', $this->base.'/storage');

    [, $inside] = sizerMeasure($this, ['storage' => $this->base.'/storage']);

    expect($inside(DiskTarget::Storage)['status'])->toBe(Status::Unreadable)
        ->and($inside(DiskTarget::ProjectTotal)['bytes'])->toBe(10);
});

it('marks everything unreadable when the project root does not resolve', function () {
    $sizer = new FolderSizer($this->scratch.'/nope', $this->scratch.'/nope/storage');
    $result = $sizer->measure();

    expect($result->totalStatus())->toBe(Status::Unreadable)
        ->and($result->total())->toBe(0);
});

it('counts an unreadable subfolder as partial, under the project root and in storage', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root can read everything');
    }

    sizerTree($this->base, ['vendor/ok/a.php' => 10, 'vendor/x/b.php' => 10, 'storage/logs/sub/c.log' => 10, 'storage/app/public/p' => 5]);
    chmod($this->base.'/vendor/x', 0000);
    chmod($this->base.'/storage/logs/sub', 0000);

    [, $row] = sizerMeasure($this);

    chmod($this->base.'/vendor/x', 0777);
    chmod($this->base.'/storage/logs/sub', 0777);

    expect($row(DiskTarget::Vendor)['status'])->toBe(Status::Partial)
        ->and($row(DiskTarget::Vendor)['bytes'])->toBe(10)
        ->and($row(DiskTarget::Storage)['status'])->toBe(Status::Partial)
        ->and($row(DiskTarget::StorageLogs)['status'])->toBe(Status::Partial)
        ->and($row(DiskTarget::StorageAppPublic)['status'])->toBe(Status::Ok)
        ->and($row(DiskTarget::ProjectTotal)['status'])->toBe(Status::Partial);
});

it('stops at the entry cap: finished buckets stay ok, the rest and the total are partial', function () {
    sizerTree($this->base, [
        'storage/a' => 1, 'storage/b' => 1, 'storage/c' => 1,
        'vendor/a' => 1, 'vendor/b' => 1, 'vendor/c' => 1, 'vendor/d' => 1, 'vendor/e' => 1, 'vendor/f' => 1,
        'node_modules/a' => 1,
    ]);

    [$result, $row] = sizerMeasure($this, ['entries' => 5]);

    expect($result->budgetHit)->toBeTrue()
        ->and($row(DiskTarget::Storage)['status'])->toBe(Status::Ok)
        ->and($row(DiskTarget::Storage)['bytes'])->toBe(3)
        ->and($row(DiskTarget::Vendor)['status'])->toBe(Status::Partial)
        ->and($row(DiskTarget::NodeModules)['status'])->toBe(Status::Partial)
        ->and($row(DiskTarget::Git)['status'])->toBe(Status::Missing)
        ->and($row(DiskTarget::ProjectTotal)['status'])->toBe(Status::Partial);
});

it('stops at the time budget using hrtime inside the loop', function () {
    $files = [];

    for ($i = 0; $i < 700; $i++) {
        $files["vendor/f{$i}"] = 1;
    }

    sizerTree($this->base, $files);

    [$result, $row] = sizerMeasure($this, ['seconds' => 0]);

    expect($result->budgetHit)->toBeTrue()
        ->and($row(DiskTarget::Vendor)['status'])->toBe(Status::Partial)
        ->and($row(DiskTarget::Vendor)['bytes'])->toBeLessThan(700);
});

it('does not block on a FIFO and counts nothing for it', function () {
    if (! function_exists('posix_mkfifo')) {
        $this->markTestSkipped('posix_mkfifo is not available');
    }

    sizerTree($this->base, ['vendor/a.php' => 4]);
    posix_mkfifo($this->base.'/vendor/pipe', 0600);

    [, $row] = sizerMeasure($this);

    expect($row(DiskTarget::Vendor)['bytes'])->toBe(4)
        ->and($row(DiskTarget::Vendor)['files'])->toBe(1);
});

it('stops when the progress callback says the run was superseded', function () {
    $files = [];

    for ($i = 0; $i < 1200; $i++) {
        $files["vendor/f{$i}"] = 1;
    }

    sizerTree($this->base, $files);

    [$result] = sizerMeasure($this, ['progress' => fn () => false]);

    expect($result->aborted)->toBeTrue();
});

it('reports progress with a bucket, files and bytes', function () {
    sizerTree($this->base, ['storage/a' => 5, 'vendor/b' => 7]);
    $seen = [];

    sizerMeasure($this, ['progress' => function (DiskTarget $bucket, int $files, int $bytes) use (&$seen) {
        $seen[] = [$bucket->value, $files, $bytes];

        return true;
    }]);

    expect($seen)->toContain(['storage', 0, 0], ['storage', 1, 5], ['vendor', 1, 7]);
});

it('never returns a path, a file name or the temp directory', function () {
    sizerTree($this->base, ['secret-customer-dir/payroll-2026.xlsx' => 10, 'storage/logs/x.log' => 1]);
    symlink($this->outside, $this->base.'/linkdir');

    [$result] = sizerMeasure($this);
    $dump = json_encode(array_map(fn (array $row) => [...$row, 'status' => $row['status']->value], $result->rows));

    expect($dump)->not->toContain($this->scratch)->not->toContain('payroll')->not->toContain('secret-customer-dir')->not->toContain('linkdir');
});

it('uses stat functions only: no file reads, hashing, mime sniffing or touching', function () {
    $source = file_get_contents(app_path('Support/Monitoring/FolderSizer.php'));
    $code = implode('', array_map(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token), token_get_all($source)));

    foreach (['fopen', 'file_get_contents', 'file_put_contents', 'readfile', 'fgets', 'fread', 'file', 'hash_file', 'md5_file', 'sha1_file', 'touch', 'copy', 'unlink', 'rmdir', 'rename', 'chmod', 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'set_time_limit', 'mime_content_type'] as $function) {
        expect($code)->not->toMatch('/(?<![\w>:$])'.$function.'\s*\(/');
    }

    foreach (['finfo', 'getRealPath', 'FOLLOW_SYMLINKS', 'Process', 'Log::'] as $needle) {
        expect($code)->not->toContain($needle);
    }
});
