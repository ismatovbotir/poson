<?php

use Tests\Support\MigrationTimestampScanner;

$migrationFixture = function (string $body, string $method = 'table'): string {
    return "<?php\nreturn new class {\n public function up(): void {\n  Schema::{$method}('t', function (Blueprint \$table) {\n{$body}\n  });\n }\n};\n";
};

it('flags a NOT NULL timestamp added to an existing table', function (string $column) use ($migrationFixture) {
    expect(MigrationTimestampScanner::violationsIn($migrationFixture("   \$table->{$column}('seen_at');")))->toHaveCount(1);
})->with(['timestamp', 'dateTime', 'timestampTz', 'dateTimeTz']);

it('flags multi-line chains and ->change() without a safe modifier', function () use ($migrationFixture) {
    $multiLine = $migrationFixture("   \$table->timestamp('seen_at')\n       ->after('name')\n       ->index();");
    $change = $migrationFixture("   \$table->dateTime('seen_at')->change();");
    $commented = $migrationFixture("   // \$table->timestamp('x')->nullable();\n   \$table->timestamp('seen_at');");

    expect(MigrationTimestampScanner::violationsIn($multiLine))->toHaveCount(1)
        ->and(MigrationTimestampScanner::violationsIn($change))->toHaveCount(1)
        ->and(MigrationTimestampScanner::violationsIn($commented))->toHaveCount(1);
});

it('passes nullable, default, useCurrent, timestamps(), softDeletes() and Schema::create', function () use ($migrationFixture) {
    $safe = $migrationFixture(<<<'PHP'
       $table->timestamp('a')->nullable();
       $table->timestamp('b')
           ->after('a')
           ->useCurrent();
       $table->dateTime('c')->default('2026-01-01 00:00:00');
       $table->dateTime('d')->nullable()->change();
       $table->timestamps();
       $table->softDeletes();
    PHP);

    expect(MigrationTimestampScanner::violationsIn($safe))->toBe([])
        ->and(MigrationTimestampScanner::violationsIn($migrationFixture("   \$table->timestamp('seen_at');", 'create')))->toBe([]);
});

it('flags nullable(false) and default(null) as unsafe', function () use ($migrationFixture) {
    expect(MigrationTimestampScanner::violationsIn($migrationFixture("   \$table->timestamp('a')->nullable(false);")))->toHaveCount(1)
        ->and(MigrationTimestampScanner::violationsIn($migrationFixture("   \$table->timestamp('a')->default(null);")))->toHaveCount(1)
        ->and(MigrationTimestampScanner::violationsIn($migrationFixture("   \$table->timestamp('a')->nullable(true);")))->toBe([]);
});

it('does not scan the down() method', function () {
    $source = "<?php\nreturn new class {\n public function up(): void {\n  Schema::table('t', function (Blueprint \$table) {\n   \$table->dropColumn('a');\n  });\n }\n public function down(): void {\n  Schema::table('t', function (Blueprint \$table) {\n   \$table->timestamp('a');\n  });\n }\n};\n";

    expect(MigrationTimestampScanner::violationsIn($source))->toBe([]);
});

it('has no NOT NULL timestamp columns added to existing tables in database/migrations', function () {
    expect(MigrationTimestampScanner::scanDirectory(database_path('migrations')))->toBe([]);
});
