<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Admin\CommonPasswords;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The one rule for a password a staff member chooses (P24-7): no control
 * characters (bcrypt can't hash a NUL byte) or invalid text, 12 characters or
 * more, at most 72 bytes (bcrypt ignores the rest), different from the
 * current one, not built on their name, their email or "poson", no runs of a
 * single character, not on the bundled common list. No composition rules and
 * no external call.
 *
 * The "differs from current" check is an oracle for the current password, so
 * run this rule only after the current password was verified.
 */
class StaffPassword implements ValidationRule
{
    public const MIN_CHARACTERS = 12;

    public const MAX_BYTES = 72;

    /**
     * Name and email parts shorter than this are not checked.
     */
    private const MIN_PART_LENGTH = 3;

    /**
     * This many identical characters in a row counts as a run.
     */
    private const RUN_LENGTH = 4;

    public function __construct(protected User $user) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, #[\SensitiveParameter] mixed $value, Closure $fail): void
    {
        $failure = is_string($value) ? $this->failure($value) : 'too_short';

        if ($failure !== null) {
            $fail(__('staff.password_rule.'.$failure, ['min' => self::MIN_CHARACTERS, 'max' => self::MAX_BYTES]));
        }
    }

    protected function failure(#[\SensitiveParameter] string $password): ?string
    {
        return match (true) {
            preg_match('/\p{Cc}/u', $password) !== 0 => 'control_character',
            mb_strlen($password) < self::MIN_CHARACTERS => 'too_short',
            strlen($password) > self::MAX_BYTES => 'too_long',
            $this->usesPersonalWord($password) => 'personal',
            preg_match('/(.)\1{'.(self::RUN_LENGTH - 1).',}/u', $password) === 1 => 'run',
            CommonPasswords::contains($password) => 'common',
            Hash::check($password, (string) $this->user->getAuthPassword()) => 'same_as_current',
            default => null,
        };
    }

    protected function usesPersonalWord(#[\SensitiveParameter] string $password): bool
    {
        $lower = mb_strtolower($password);
        $compact = self::compact($lower);

        foreach ($this->personalWords() as $word) {
            if (str_contains($lower, $word) || str_contains($compact, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function personalWords(): array
    {
        $name = mb_strtolower((string) $this->user->name);
        $local = mb_strtolower((string) strstr((string) $this->user->email, '@', true));

        $words = [
            'poson',
            self::compact($name),
            self::compact($local),
            ...(preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            ...(preg_split('/[^\p{L}\p{N}]+/u', $local, -1, PREG_SPLIT_NO_EMPTY) ?: []),
        ];

        return array_values(array_unique(array_filter($words, fn (string $word): bool => mb_strlen($word) >= self::MIN_PART_LENGTH)));
    }

    private static function compact(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
    }
}
