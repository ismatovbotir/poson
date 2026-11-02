<?php

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;

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
    Customer::class, Contact::class, Activity::class,
];

/**
 * Arch tests are declared before the application boots, so no base_path().
 */
function mcpRoot(string $path = ''): string
{
    return dirname(__DIR__, 3).($path === '' ? '' : '/'.$path);
}

/**
 * @return list<string>
 */
function mcpPhpFiles(): array
{
    $files = [];

    foreach (['app/Mcp', 'app/Actions/Mcp'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(mcpRoot($dir), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

/**
 * App\Support classes that App\Mcp and App\Actions\Mcp depend on.
 *
 * @return list<string>
 */
function mcpSupportClasses(): array
{
    $classes = [];

    foreach (mcpPhpFiles() as $path) {
        preg_match_all('/^use (App\\\\Support\\\\[A-Za-z\\\\]+);/m', (string) file_get_contents($path), $matches);
        array_push($classes, ...$matches[1]);
    }

    return array_values(array_unique($classes));
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

arch('MCP never uses Telegram, Customer, Contact or Activity models')
    ->expect(MCP_NAMESPACES)
    ->not->toUse(MCP_FORBIDDEN_CLASSES);

arch('the App\Support classes behind MCP never use Telegram, Customer, Contact or Activity models')
    ->expect(mcpSupportClasses())
    ->not->toUse(MCP_FORBIDDEN_CLASSES);

it('has an allow-list that names real models and a real support list', function () {
    $existing = array_map(fn (string $path): string => basename($path, '.php'), glob(mcpRoot('app/Models/*.php')));

    expect(array_diff(MCP_ALLOWED_MODELS, $existing))->toBe([])
        ->and(mcpSupportClasses())->toContain('App\Support\Dashboard\BusinessMetrics', 'App\Support\Orders\OrderMetrics');
});

/**
 * Source text without comments, so a docblock may say "customers".
 */
function mcpCodeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

it('keeps Telegram, contact and customer data out of the MCP code', function () {
    // The one static-page description that lists the "contacts" page slug (site content, not CRM data).
    $allowedPhrases = ['(about, contacts, delivery, warranty, legal and custom pages)'];

    $files = array_merge(mcpPhpFiles(), array_map(
        fn (string $class): string => mcpRoot('app/'.str_replace('\\', '/', substr($class, 4)).'.php'),
        mcpSupportClasses(),
    ));

    expect($files)->not->toBeEmpty();

    foreach ($files as $path) {
        $code = str_replace($allowedPhrases, '', mcpCodeWithoutComments($path));

        foreach (['telegram_', 'contacts', 'customers', 'leads.message'] as $needle) {
            expect(stripos($code, $needle))->toBeFalse(basename($path)." contains \"{$needle}\"");
        }
    }
});
