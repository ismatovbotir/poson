<?php

namespace App\Support\Admin;

/**
 * Bundled list of common passwords (P24-7), so the check makes no external
 * call. Entries are lower-case. contains() also looks through the usual
 * disguises: separators, leading or trailing digits and symbols, simple
 * look-alike letters ("p@ssw0rd") and repeats ("qwertyqwerty").
 */
final class CommonPasswords
{
    private const LIST = [
        // Words
        'password', 'passw0rd', 'passphrase', 'parol', 'parolim', 'пароль', 'мойпароль', 'secret', 'secretpassword',
        'mypassword', 'newpassword', 'changeme', 'changemenow', 'changethis', 'temppassword', 'temporary', 'default',
        'defaultpassword', 'letmein', 'letmeinnow', 'welcome', 'welcomeback', 'welcomehome', 'iloveyou', 'loveyou',
        'trustno', 'trustnoone', 'whatever', 'nothing', 'nopassword', 'unknown', 'forgetit', 'donotforget',
        'sunshine', 'princess', 'football', 'baseball', 'basketball', 'superman', 'batman', 'spiderman', 'starwars',
        'pokemon', 'freedom', 'shadow', 'master', 'monkey', 'dragon', 'michael', 'jennifer', 'jordan', 'charlie',
        'computer', 'internet', 'samsung', 'google', 'facebook', 'instagram', 'telegram', 'microsoft', 'windows',
        'liverpool', 'chelsea', 'arsenal', 'barcelona', 'realmadrid', 'manchester', 'hellokitty', 'helloworld',
        'привет', 'приветмир', 'любовь', 'наташа', 'россия', 'москва', 'ташкент', 'узбекистан',
        'salom', 'salomdunyo', 'toshkent', 'tashkent', 'ozbekiston', 'uzbekistan', 'samarqand', 'samarkand', 'bukhara',
        // Roles and systems
        'admin', 'administrator', 'adminadmin', 'adminpassword', 'superadmin', 'superuser', 'sysadmin', 'root',
        'rootpassword', 'manager', 'operator', 'support', 'service', 'company', 'office', 'business', 'database',
        'laravel', 'mysql', 'server', 'testing', 'testtest', 'testpassword', 'test', 'guest', 'demo', 'demopassword',
        'user', 'username', 'userpassword', 'login', 'loginpassword', 'qwertypassword',
        // Keyboard patterns
        'qwerty', 'qwertyu', 'qwertyui', 'qwertyuiop', 'qwertyuiopas', 'qwertyuiopasdf', 'qwertyuiopasdfghjkl',
        'asdf', 'asdfgh', 'asdfghjk', 'asdfghjkl', 'asdfghjklzxc', 'zxcv', 'zxcvbn', 'zxcvbnm', 'zxcvbnmasdf',
        'qazwsx', 'qazwsxedc', 'qazwsxedcrfv', 'qazwsxedcrfvtgb', '1qaz2wsx', '1qaz2wsx3edc', '1qaz2wsx3edc4rfv',
        '1q2w3e', '1q2w3e4r', '1q2w3e4r5t', '1q2w3e4r5t6y', '1q2w3e4r5t6y7u', 'q1w2e3r4t5y6', 'q1w2e3r4', 'a1b2c3d4e5f6',
        'abc', 'abcd', 'abcdef', 'abcdefgh', 'abcdefghijkl', 'abcdefghijklmnop', 'aabbccddeeff', 'qweasd', 'qweasdzxc',
        'qweqweqwe', 'asdasdasd', 'zxczxczxc', 'йцукен', 'йцукенгшщз', 'йцукенгшщзхъ', 'фывапролдж', 'ячсмитьбю',
        // Numbers
        '123456789012', '1234567890123', '12345678901234', '123456789012345', '1234567890123456', '098765432109',
        '0987654321', '987654321012', '210987654321', '012345678901', '112233445566', '111222333444', '121212121212',
        '123123123123', '123321123321', '123456123456', '123456654321', '147258369147', '147852369147', '159357159357',
        '123', '1234', '12345', '123456', '1234567', '12345678', '123456789', '1234567890',
        // Common phrases written together
        'correcthorsebatterystaple', 'iloveyouforever', 'thequickbrownfox', 'thequickbrownfoxjumps', 'opensesame',
        'letmeinplease', 'passwordpassword', 'mypasswordisgood', 'thisismypassword', 'thisisapassword', 'strongpassword',
        'verystrongpassword', 'supersecret', 'supersecretpassword', 'topsecret', 'verysecret', 'goodpassword',
    ];

    private const MAX_SHORT_UNIT = 4;

    private const LOOK_ALIKES = ['@' => 'a', '$' => 's', '0' => 'o', '3' => 'e', '1' => 'l', '5' => 's', '7' => 't'];

    public static function contains(#[\SensitiveParameter] string $password): bool
    {
        static $lookup = null;
        $lookup ??= array_flip(self::LIST);

        foreach (self::candidates($password) as $candidate) {
            if (isset($lookup[$candidate])) {
                return true;
            }
        }

        // A short unit repeated to reach the length ("abababababab").
        return preg_match('/^(.{1,'.self::MAX_SHORT_UNIT.'}?)\1+$/u', (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($password))) === 1;
    }

    /**
     * @return list<string>
     */
    private static function candidates(#[\SensitiveParameter] string $password): array
    {
        $lower = mb_strtolower(trim($password));
        $alphanumeric = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $lower);
        $core = (string) preg_replace('/^[\d\W_]+|[\d\W_]+$/u', '', $lower);
        $coreAlphanumeric = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $core);
        $plain = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', strtr($core, self::LOOK_ALIKES));

        $candidates = [$lower, $alphanumeric, $core, $coreAlphanumeric, $plain];

        foreach ([$alphanumeric, $coreAlphanumeric, $plain] as $text) {
            if (preg_match('/^(.+?)\1+$/u', $text, $matches) === 1) {
                $candidates[] = $matches[1];
            }
        }

        return array_values(array_unique(array_filter($candidates, fn (string $candidate): bool => $candidate !== '')));
    }
}
