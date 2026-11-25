<?php

use App\Models\Activity;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\BusinessConnection;
use App\Models\BusinessMessage;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Models\WebChat;
use App\Models\WebChatMessage;
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
    Customer::class, Contact::class, Activity::class, Repair::class, WebChat::class, WebChatMessage::class,
    // Plan 41 (VO-17): Virtual office (Telegram Business) models.
    BusinessConnection::class, BusinessChat::class, BusinessMessage::class, BusinessChatAnalysis::class, AgentProfile::class, AgentProfileVersion::class,
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

/**
 * Plan 20 MA-18: the Mini App columns orders.telegram_user_id and carts.telegram_user_id (and the
 * Mini App code) never reach MCP. The scanner's "telegram_" needle must keep catching the column.
 */
it('keeps the Mini App columns and classes out of MCP (plan 20 MA-18)', function () {
    expect(McpExclusionScanner::labels("Order::query()->where('telegram_user_id', 1)"))->toContain('telegram_')
        ->and(McpExclusionScanner::labels('$cart->telegram_user_id'))->toContain('telegram_');

    foreach (array_merge(mcpPhpFiles('app/Mcp', 'app/Actions/Mcp'), mcpSupportFiles()) as $path) {
        $code = McpExclusionScanner::withoutComments((string) file_get_contents(mcpRoot($path)));

        expect($code)->not->toContain('telegram_user_id', $path)
            ->not->toContain('telegramUser', $path)
            ->not->toContain('MiniApp', $path)
            ->not->toContain('OrderSource::Telegram', $path);
    }
});

arch('MCP never uses the Telegram Mini App code (plan 20 MA-18)')
    ->expect(MCP_NAMESPACES)
    ->not->toUse(['App\Support\Telegram\MiniApp', 'App\Http\Controllers\Tma', 'App\Actions\Telegram\MiniApp']);

/**
 * Plan 28 C5d (CB-26, WC-12): website chats are visitor data. Neither the models, the tables nor
 * the chat actions and admin screens may be reached from MCP code (or the Support classes behind it).
 */
const WEB_CHAT_NAMESPACES = ['App\Actions\Chat\Web', 'App\Livewire\Admin\Chat', 'App\Livewire\Site\Chat'];

arch('MCP never uses the website chat actions or screens')
    ->expect(MCP_NAMESPACES)
    ->not->toUse(WEB_CHAT_NAMESPACES);

arch('the App\Support classes behind MCP never use the website chat actions or screens')
    ->expect(mcpSupportClasses())
    ->not->toUse(WEB_CHAT_NAMESPACES);

it('keeps website chat models and tables out of MCP and sees them in the scanner', function () {
    foreach (array_merge(mcpPhpFiles('app/Mcp', 'app/Actions/Mcp'), mcpSupportFiles()) as $path) {
        $code = McpExclusionScanner::withoutComments((string) file_get_contents(mcpRoot($path)));

        expect(preg_match('/\bWebChat(?:Message|Version|Token|Dialog|Backlog)?\b|web_chat/', $code))->toBe(0, "{$path} reaches website chats");
    }

    expect(McpExclusionScanner::CRM_MODELS)->toContain('WebChat', 'WebChatMessage')
        ->and(McpExclusionScanner::tableNeedle())->toContain('web_chats')->toContain('web_chat_messages')
        ->and(McpExclusionScanner::labels('<?php return DB::table("web_chats")->count();', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name')
        ->and(McpExclusionScanner::labels('<?php return DB::table("web_chat_messages")->count();', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name')
        ->and(McpExclusionScanner::labels('<?php return DB::table("x")->where("web_chats.id", 1);', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name');
});

/**
 * Plan 41 (VO-17): Telegram Business chats, messages and analyses are private conversations.
 */
it('keeps every Virtual office model and table out of MCP and catches a referencing fixture', function () {
    expect(McpExclusionScanner::CRM_MODELS)->toContain('BusinessConnection', 'BusinessChat', 'BusinessMessage', 'BusinessChatAnalysis', 'AgentProfile', 'AgentProfileVersion')
        ->and(McpExclusionScanner::labels('<?php return DB::table("business_chats")->count();', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name', 'business_')
        ->and(McpExclusionScanner::labels('<?php return $row->where("business_connection_id", 1);', 'app/Mcp/Tools/Fixture.php'))->toContain('business_')
        ->and(McpExclusionScanner::labels('<?php return DB::table("agent_profile_versions")->get();', 'app/Mcp/Tools/Fixture.php'))->toContain('CRM table name');

    foreach (array_merge(mcpPhpFiles('app/Mcp', 'app/Actions/Mcp'), mcpSupportFiles()) as $path) {
        expect(McpExclusionScanner::withoutComments((string) file_get_contents(mcpRoot($path))))->not->toMatch('/\\bBusiness(?:Chat|Message|Connection|ChatAnalysis)\\b|AgentProfile|Office\\\\/', $path);
    }
});
