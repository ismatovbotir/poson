<?php

namespace Tests\Support;

/**
 * Static guard for the MySQL "Invalid default value" trap: adding a NOT NULL
 * timestamp or datetime column to an existing table (Schema::table) without a
 * default fails on rows that already exist. Migrations are only read as text
 * (never required or executed); comments are dropped with the PHP tokenizer
 * and each `$table->...;` statement is checked whole, so multi-line chains
 * and `->change()` are covered. Schema::create is allowed (the table is empty). The down() body is skipped:
 * rollbacks re-add dropped columns and never run against production data.
 */
final class MigrationTimestampScanner
{
    private const COLUMN_PATTERN = '/\$\w+->(?:timestamp|dateTime|timestampTz|dateTimeTz)\(/';

    private const SAFE_PATTERN = '/->(?:nullable\(\s*(?:true)?\s*\)|default\(\s*(?!null\b)|useCurrent\()/';

    /**
     * @return list<string> the offending statements (whitespace collapsed)
     */
    public static function violationsIn(string $source): array
    {
        $code = self::stripDownMethod(self::stripComments($source));
        preg_match_all('/Schema::(?:connection\([^)]*\)->)?(table|create)\(/', $code, $schemas, PREG_OFFSET_CAPTURE);
        preg_match_all(self::COLUMN_PATTERN, $code, $columns, PREG_OFFSET_CAPTURE);

        $violations = [];

        foreach ($columns[0] as [, $offset]) {
            $mode = null;

            foreach ($schemas[1] as [$kind, $schemaOffset]) {
                if ($schemaOffset < $offset) {
                    $mode = $kind;
                }
            }

            if ($mode !== 'table') {
                continue;
            }

            $end = strpos($code, ';', $offset);
            $statement = trim(preg_replace('/\s+/', ' ', substr($code, $offset, $end === false ? null : $end - $offset)));

            if (! preg_match(self::SAFE_PATTERN, $statement)) {
                $violations[] = $statement;
            }
        }

        return $violations;
    }

    /**
     * @return array<string, list<string>> file name => offending statements
     */
    public static function scanDirectory(string $directory): array
    {
        $result = [];

        foreach (glob(rtrim($directory, '/').'/*.php') ?: [] as $file) {
            $name = basename($file);

            $violations = self::violationsIn((string) file_get_contents($file));

            if ($violations !== []) {
                $result[$name] = $violations;
            }
        }

        return $result;
    }

    private static function stripDownMethod(string $code): string
    {
        if (! preg_match('/function\s+down\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/', $code, $m, PREG_OFFSET_CAPTURE)) {
            return $code;
        }

        $start = $m[0][1];
        $depth = 0;

        for ($i = $start + strlen($m[0][0]) - 1, $n = strlen($code); $i < $n; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}' && --$depth === 0) {
                return substr($code, 0, $start).substr($code, $i + 1);
            }
        }

        return substr($code, 0, $start);
    }

    private static function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
