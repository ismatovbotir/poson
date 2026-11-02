<?php

namespace App\Support\Admin;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Read-only catalogue of artisan commands for Admin → System → Commands
 * (decision 2026-11-02).
 *
 * Builds plain arrays from the static command definitions only: names,
 * descriptions, help, synopsis, argument and option metadata. Commands are
 * never run or instantiated here beyond what Artisan::all() already holds,
 * and no current/config/env value is read. The list is cached (plain arrays,
 * never Command objects) so a Livewire request filters in PHP without
 * touching Artisan.
 *
 * Rules:
 *  - hidden commands are not listed;
 *  - the Symfony global options (--help, --quiet, ...) are not listed;
 *  - the default of an argument or option whose name looks sensitive
 *    (token, secret, password, key, ...) is never shown;
 *  - only scalar defaults are shown, as text, truncated;
 *  - Symfony console style tags (<info>, <comment>, <fg=...>) are stripped.
 *
 * @phpstan-type Param array{name: string, shortcut: ?string, description: string, required: bool, is_array: bool, accepts_value: bool, default: ?string}
 * @phpstan-type Item array{name: string, description: string, help: string, usage: string, arguments: list<Param>, options: list<Param>, is_app: bool, schedule: list<array{expression: string, human: ?string}>}
 */
final class CommandCatalog
{
    public const CACHE_TTL = 600;

    private const APP_NAMESPACE = 'App\\Console\\Commands\\';

    private const GLOBAL_OPTIONS = ['help', 'silent', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'];

    private const SENSITIVE_NAME = '/(key|token|secret|pass|credential|private|\bpin\b)/i';

    private const TEXT_LIMIT = 4000;

    /**
     * @return list<Item>
     */
    public static function all(): array
    {
        return Cache::remember(self::cacheKey(), self::CACHE_TTL, fn (): array => self::build());
    }

    public static function forget(): void
    {
        foreach ((array) config('app.supported_locales', ['uz', 'ru', 'en']) as $locale) {
            Cache::forget(self::cacheKey((string) $locale));
        }
    }

    public static function cacheKey(?string $locale = null): string
    {
        $deploy = 0;

        foreach ([...glob(app_path('Console/Commands/*.php')) ?: [], base_path('routes/console.php')] as $file) {
            $deploy = max($deploy, (int) @filemtime($file));
        }

        return 'admin:commands:catalog:'.md5(app()->version().'|'.$deploy.'|'.($locale ?? app()->getLocale()));
    }

    /**
     * Case-insensitive substring search over name, description and help.
     *
     * @param  list<Item>  $items
     * @return list<Item>
     */
    public static function filter(array $items, string $search, bool $withFramework): array
    {
        $search = trim(mb_substr($search, 0, 100));

        return array_values(array_filter($items, function (array $item) use ($search, $withFramework): bool {
            if (! $withFramework && ! $item['is_app']) {
                return false;
            }

            return $search === ''
                || mb_stripos($item['name'], $search) !== false
                || mb_stripos($item['description'], $search) !== false
                || mb_stripos($item['help'], $search) !== false;
        }));
    }

    /**
     * Removes Symfony console style tags; any other markup is left as plain
     * text (the view escapes it).
     */
    public static function plain(string $text): string
    {
        $text = preg_replace('#</?(?:info|comment|error|question|(?:fg|bg|options|href)=[^>]*)?>#i', '', $text) ?? $text;

        return mb_substr(trim(str_replace("\r", '', $text)), 0, self::TEXT_LIMIT);
    }

    /**
     * @return list<Item>
     */
    private static function build(): array
    {
        $commands = Artisan::all();
        $schedule = self::schedule();
        $items = [];

        foreach ($commands as $name => $command) {
            if (! $command instanceof SymfonyCommand || $command->isHidden()) {
                continue;
            }

            $isApp = $command instanceof ClosureCommand || str_starts_with($command::class, self::APP_NAMESPACE);
            $name = (string) $name;

            $items[] = [
                'name' => $name,
                'description' => self::plain($command->getDescription()),
                'help' => self::plain(str_replace(['%command.name%', '%command.full_name%'], $name, $command->getHelp())),
                'usage' => self::plain($command->getSynopsis()),
                'arguments' => array_map(self::argument(...), array_values($command->getDefinition()->getArguments())),
                'options' => array_values(array_map(self::option(...), array_filter(
                    $command->getDefinition()->getOptions(),
                    fn (InputOption $option): bool => ! in_array($option->getName(), self::GLOBAL_OPTIONS, true),
                ))),
                'is_app' => $isApp,
                'schedule' => $isApp ? ($schedule[$name] ?? []) : [],
            ];
        }

        usort($items, fn (array $a, array $b): int => [! $a['is_app'], $a['name']] <=> [! $b['is_app'], $b['name']]);

        return $items;
    }

    /**
     * Cron expressions per command name, from the Schedule instance only.
     *
     * @return array<string, list<array{expression: string, human: ?string}>>
     */
    private static function schedule(): array
    {
        $result = [];

        try {
            $events = app(Schedule::class)->events();
        } catch (Throwable) {
            return [];
        }

        foreach ($events as $event) {
            /** @var Event $event */
            if (preg_match('/artisan[\'"]?\s+([\w:.-]+)/', (string) $event->command, $match) !== 1) {
                continue;
            }

            $expression = (string) $event->expression;
            $result[$match[1]][$expression] = ['expression' => $expression, 'human' => self::human($expression)];
        }

        return array_map(array_values(...), $result);
    }

    private static function human(string $expression): ?string
    {
        return match (true) {
            $expression === '* * * * *' => 'every minute',
            preg_match('#^\*/(\d+) \* \* \* \*$#', $expression, $m) === 1 => "every {$m[1]} minutes",
            $expression === '0 * * * *' => 'hourly',
            preg_match('#^(\d+) (\d+) \* \* \*$#', $expression, $m) === 1 => sprintf('daily at %02d:%02d', $m[2], $m[1]),
            preg_match('#^(\d+) (\d+) \* \* (\d)$#', $expression, $m) === 1 => sprintf('weekly (day %d) at %02d:%02d', $m[3], $m[2], $m[1]),
            default => null,
        };
    }

    /**
     * @return Param
     */
    private static function argument(InputArgument $argument): array
    {
        return [
            'name' => $argument->getName(),
            'shortcut' => null,
            'description' => self::plain($argument->getDescription()),
            'required' => $argument->isRequired(),
            'is_array' => $argument->isArray(),
            'accepts_value' => true,
            'default' => self::default($argument->getName(), $argument->getDefault()),
        ];
    }

    /**
     * @return Param
     */
    private static function option(InputOption $option): array
    {
        return [
            'name' => $option->getName(),
            'shortcut' => $option->getShortcut(),
            'description' => self::plain($option->getDescription()),
            'required' => $option->isValueRequired(),
            'is_array' => $option->isArray(),
            'accepts_value' => $option->acceptValue(),
            'default' => self::default($option->getName(), $option->getDefault()),
        ];
    }

    private static function default(string $name, mixed $value): ?string
    {
        if (preg_match(self::SENSITIVE_NAME, $name) === 1 || $value === null || $value === false || $value === [] || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        if (! is_scalar($value)) {
            return null;
        }

        return mb_substr(self::plain($value === true ? 'true' : (string) $value), 0, 100);
    }
}
