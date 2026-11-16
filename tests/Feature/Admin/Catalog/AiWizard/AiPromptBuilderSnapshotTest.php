<?php

use App\Enums\AiFieldKind;
use App\Support\Content\AiFill\AiField;
use App\Support\Content\AiFill\AiPromptBuilder;

/**
 * Plan 32 PW-25: extracting the shared prompt rules (AiPromptRules) left the
 * Plan 29 paste prompt byte-identical. The snapshot was taken before the
 * extraction; regenerate only on a deliberate prompt change.
 */
function promptBuilderSnapshotInput(): string
{
    return app(AiPromptBuilder::class)->build('product', [
        new AiField('name', 'admin.catalog.name', AiFieldKind::Plain, 255, uzRequired: true, hint: 'Product name.'),
        new AiField('short_description', 'admin.products.fields.short_description', AiFieldKind::Plain, 500, multiline: true),
        new AiField('description', 'admin.products.fields.description', AiFieldKind::Html, 20000),
    ], [
        'sku' => 'ZD421-203',
        'brand' => 'Zebra',
        'key specifications' => ['Resolution: 203 dpi', ''],
        'empty' => '',
    ], [
        'name' => ['uz' => 'Zebra ZD421', 'ru' => "Принтер\u{202E} Zebra", 'en' => ''],
        'description' => ['en' => str_repeat('a', 1600)],
    ]);
}

it('keeps the paste prompt byte-identical', function () {
    $path = base_path('tests/Fixtures/ai-prompt-builder.snapshot.txt');

    expect(is_file($path))->toBeTrue();

    expect(promptBuilderSnapshotInput())->toBe(file_get_contents($path));
});
