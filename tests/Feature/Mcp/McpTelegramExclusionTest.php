<?php

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use Tests\Support\McpExclusionScanner;

/**
 * Plan 14 v2 §12, TG-36: the MCP server must never reach Telegram conversations
 * or CRM contact data. A future MCP CRM tool needs a new security sign-off.
 *
 * Pest's toOnlyUse() would force listing every framework class too, so the
 * App\Models allow-list is enforced exactly the other way round: every model
 * NOT on the allow-list (new ones included) must not be used.
 */
const MCP_NAMESPACES = ['App\Mcp', 'App\Actions\Mcp'];

/** Models App\Mcp and App\Actions\Mcp use today. */
const MCP_ALLOWED_MODELS = [
    'ApiToken', 'Attribute', 'AttributeOption', 'Brand', 'Category', 'FaqItem', 'Lead', 'McpCall',
    'NewsPost', 'Page', 'Product', 'ProductAttributeValue', 'Quote', 'SettingsAudit',
    'TrafficDaily', 'TrafficDailyPage', 'User', 'Video',
];

const MCP_FORBIDDEN_CLASSES = [
    TelegramUser::class, TelegramMessage::class, TelegramUpdate::class,
    Customer::class, Contact::class, Activity::class, Repair::class,
];

/**
 * Arch tests are declared before the application boots, so no base_path().
 */
function mcpRoot(string $path = ''): string
{
    return dirname(__DIR__, 3).($path === '' ? '' : '/'.$path);
}

/**
 * @return list<string> repo-relative paths of every PHP file under the directory
 */
function mcpPhpFiles(string ...$dirs): array
{
    $files = [];

    foreach ($dirs as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(mcpRoot($dir), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $dir.substr($file->getPathname(), strlen(mcpRoot($dir)));
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * App\Support classes (repo-relative files) that MCP depends on, followed
 * recursively through `use` lines and fully-qualified references.
 *
 * @return list<string>
 */
function mcpSupportFiles(): array
{
    $queue = mcpPhpFiles('app/Mcp', 'app/Actions/Mcp');
    $seen = [];
    $support = [];

    while ($queue !== []) {
        $path = array_pop($queue);

        if (isset($seen[$path])) {
            continue;
        }

        $seen[$path] = true;
        preg_match_all('/\\\\?\bApp\\\\Support\\\\[A-Za-z0-9_\\\\]+/', (string) file_get_contents(mcpRoot($path)), $matches);

        foreach (array_unique($matches[0]) as $class) {
            $file = 'app/'.str_replace('\\', '/', substr(ltrim($class, '\\'), 4)).'.php';

            if (is_file(mcpRoot($file)) && ! isset($seen[$file])) {
                $queue[] = $file;
                $support[$file] = true;
            }
        }
    }

    $files = array_keys($support);
    sort($files);

    return $files;
}

/**
 * Support classes as fully-qualified names, for arch().
 *
 * @return list<string>
 */
function mcpSupportClasses(): array
{
    return array_map(fn (string $file): string => str_replace('/', '\\', 'App\\'.substr($file, 4, -4)), mcpSupportFiles());
}

/**
 * @return list<string>
 */
function modelsOutsideMcpAllowList(): array
{
    $models = array_map(fn (string $path): string => basename($path, '.php'), glob(mcpRoot('app/Models/*.php')));

    return array_values(array_map(fn (string $model): string => "App\\Models\\{$model}", array_diff($models, MCP_ALLOWED_MODELS)));
}

arch('MCP uses only the allow-listed App\Models classes')
    ->expect(MCP_NAMESPACES)
    ->not->toUse(modelsOutsideMcpAllowList());

arch('the App\Support classes behind MCP use only the allow-listed App\Models classes, plus the CRM aggregate models')
    ->expect(mcpSupportClasses())
    ->not->toUse(array_values(array_diff(modelsOutsideMcpAllowList(), array_map(fn (string $m): string => "App\\Models\\{$m}", ['Order', 'OrderPayment', 'UzqrTransaction']))));

arch('MCP never uses Telegram, Customer, Contact, Activity or Repair models')
    ->expect(MCP_NAMESPACES)
    ->not->toUse(MCP_FORBIDDEN_CLASSES);

arch('the App\Support classes behind MCP never use Telegram, Customer, Contact, Activity or Repair models')
    ->expect(mcpSupportClasses())
    ->not->toUse(MCP_FORBIDDEN_CLASSES);

it('keeps every repair class, table and permission out of MCP (plan 24 P24-22)', function () {
    $files = array_merge(mcpPhpFiles('app/Mcp', 'app/Actions/Mcp'), mcpSupportFiles());

    foreach ($files as $path) {
        $code = McpExclusionScanner::withoutComments((string) file_get_contents(mcpRoot($path)));

        expect(preg_match('/\bRepair(?:Status|Coverage|DeviceType|Access|Policy)?\b|repairs[._]|[\'"]repair[\'"]|Repairs\\\\/', $code))->toBe(0, "{$path} reaches the repairs domain");
    }

    expect(McpExclusionScanner::CRM_MODELS)->toContain('Repair')
        ->and(McpExclusionScanner::tableNeedle())->toContain('repairs')
        ->and(McpExclusionScanner::labels('<?php return DB::table("repairs")->count();', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name')
        ->and(McpExclusionScanner::labels('<?php return Repair::query()->where("repairs.status", 1);', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name');
});

it('finds the Support dependencies of MCP, recursively', function () {
    expect(mcpSupportFiles())->toContain(
        'app/Support/Dashboard/BusinessMetrics.php',
        'app/Support/Orders/OrderMetrics.php',
        'app/Support/Content/PostAudit.php',
        'app/Support/Site/StructuredData.php', // two levels down (PostAudit, ProductDraftRules)
    );
});

it('has an allow-list that names real models', function () {
    $existing = array_map(fn (string $path): string => basename($path, '.php'), glob(mcpRoot('app/Models/*.php')));

    expect(array_diff(MCP_ALLOWED_MODELS, $existing))->toBe([]);
});

it('lets only the aggregate files use Lead, Quote, Order or Uzqr transactions', function () {
    $models = implode('|', McpExclusionScanner::CRM_MODELS);
    $pattern = '/\b(?:App\\\\Models\\\\)?('.$models.')(?:::|::class)|use App\\\\Models\\\\('.$models.');/';

    foreach (array_merge(mcpPhpFiles('app/Mcp', 'app/Actions/Mcp'), mcpSupportFiles()) as $path) {
        if (in_array($path, McpExclusionScanner::AGGREGATE_FILES, true)) {
            continue;
        }

        expect(preg_match($pattern, McpExclusionScanner::withoutComments((string) file_get_contents(mcpRoot($path)))))->toBe(0, "{$path} touches a CRM model");
    }

    // The aggregate files do exist (otherwise this list is stale).
    foreach (McpExclusionScanner::AGGREGATE_FILES as $path) {
        expect(is_file(mcpRoot($path)))->toBeTrue($path);
    }
});

it('keeps Telegram, contact, customer, personal data and CRM table names out of the MCP code', function () {
    $files = array_merge(mcpPhpFiles('app/Mcp', 'app/Actions/Mcp'), mcpSupportFiles());

    expect($files)->not->toBeEmpty();

    foreach ($files as $path) {
        expect(McpExclusionScanner::labelsForFile(mcpRoot(), $path))->toBe([], "{$path} trips the MCP exclusion scan");
    }
});
