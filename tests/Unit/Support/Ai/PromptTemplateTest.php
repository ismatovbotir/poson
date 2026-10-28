<?php

use App\Support\Ai\FieldRegistry;
use App\Support\Ai\PromptOverrides;
use App\Support\Ai\PromptTemplate;
use Illuminate\Support\Facades\Cache;

// Unit tests never touch the database: no overrides (the cached list is empty).
beforeEach(fn () => Cache::forever(PromptOverrides::CACHE_KEY, []));

it('normalizes line endings and trims', function () {
    expect(PromptTemplate::normalize("  First line\r\nSecond\rThird \n"))->toBe("First line\nSecond\nThird");
});

it('finds unknown and malformed placeholders', function () {
    $field = FieldRegistry::get('product.description');

    expect(PromptTemplate::unknownPlaceholders($field, 'About {name} by {brand} in {category}.'))->toBe([])
        ->and(PromptTemplate::unknownPlaceholders($field, 'Use {specs}, {Name}, { name }, {question} and {specs} again.'))->toBe(['{specs}', '{Name}', '{ name }', '{question}'])
        ->and(PromptTemplate::unknownPlaceholders($field, 'Broken {name and brand}'))->toBe(['{name and brand}'])
        ->and(PromptTemplate::unknownPlaceholders($field, 'Stray { brace and } here {name'))->toBe(['{ brace and }', '{'])
        ->and(PromptTemplate::unknownPlaceholders($field, 'Closing only } here'))->toBe(['}']);
});

it('lists the allowed placeholders a text does not use', function () {
    $field = FieldRegistry::get('faq.answer');

    expect(PromptTemplate::missingPlaceholders($field, 'Answer "{question}" briefly.'))->toBe(['name', 'category'])
        ->and(PromptTemplate::missingPlaceholders($field, '{question} {name} {category}'))->toBe([]);
});

it('warns about brand rule words', function () {
    expect(PromptTemplate::brandWarnings('Mention the cheapest offer and chegirma.'))->toBe(['cheapest', 'chegirma'])
        ->and(PromptTemplate::brandWarnings('Stress setup and official warranty.'))->toBe([]);
});

it('previews a text with the localized sample record of the group', function () {
    app()->setLocale('en');
    $field = FieldRegistry::get('product.meta_title');

    expect(PromptTemplate::preview($field, 'Title for {name} ({brand}, {category}), not {specs}.'))
        ->toBe('Title for Zebra ZD421 label printer (Zebra, Printers › Label printers), not {specs}.');

    app()->setLocale('ru');

    expect(PromptTemplate::preview(FieldRegistry::get('faq.answer'), '{question} / {name}'))
        ->toBe('Какие этикетки подходят для этого принтера? / Принтеры этикеток');
});

it('has a sample record for every group in every locale', function () {
    foreach (config('app.supported_locales') as $locale) {
        app()->setLocale($locale);

        foreach (FieldRegistry::fields() as $field) {
            $context = PromptTemplate::sampleContext($field->group)->placeholders();

            foreach ($field->placeholders as $placeholder) {
                expect($context[$placeholder])->not->toBe('', "{$locale} {$field->group} {$placeholder}");
            }
        }
    }
});
