<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Support\Content\AiProduct\AiSpecParser;

/**
 * Plan 32 §6, PW-16: stage B spec proposals are validated against the
 * attributes of the product's CURRENT category chain only: codes from the
 * attribute list, numbers in range, existing option ids of that very attribute,
 * guarded plain text, unsure by default - at parse time and again at apply
 * (recheck), e.g. after a category change.
 */
beforeEach(function () {
    config(['app.url' => 'https://poson.test']);
    $this->category = Category::factory()->create();
    $this->width = Attribute::factory()->number('mm')->create(['code' => 'width']);
    $this->wireless = Attribute::factory()->boolean()->create(['code' => 'wireless']);
    $this->interface = Attribute::factory()->select(['USB', 'RS232', 'Bluetooth'])->create(['code' => 'interface']);
    $this->codes = Attribute::factory()->multiselect(['QR Code', 'DataMatrix', 'PDF417'])->create(['code' => 'codes']);
    $this->note = Attribute::factory()->create(['code' => 'note']);

    foreach ([$this->width, $this->wireless, $this->interface, $this->codes, $this->note] as $sort => $attribute) {
        $this->category->attributes()->attach($attribute, ['sort' => $sort + 1]);
    }

    $this->otherAttribute = Attribute::factory()->select(['Alien A', 'Alien B'])->create(['code' => 'alien']);
    Category::factory()->create()->attributes()->attach($this->otherAttribute, ['sort' => 1]);

    $this->parser = app(AiSpecParser::class);
    $this->optionIds = fn (Attribute $attribute): array => $attribute->options()->orderBy('sort')->pluck('id')->all();
});

function specItem(string $code, mixed $value = '', array $text = [], mixed $unsure = null): array
{
    return array_filter(['code' => $code, 'value' => $value, 'text' => $text, 'unsure' => $unsure], fn (mixed $item): bool => $item !== null);
}

describe('parse()', function () {
    it('reads every attribute type into the product form slot of that type', function () {
        [$usb, $rs232] = ($this->optionIds)($this->interface);
        $codeIds = ($this->optionIds)($this->codes);

        $result = $this->parser->parse([
            specItem('width', '12,5'),
            specItem('wireless', 'yes'),
            specItem('interface', (string) $rs232),
            specItem('codes', "{$codeIds[0]}, {$codeIds[2]}"),
            specItem('note', '', ['uz' => 'Qulay tutqich', 'ru' => 'Удобная ручка', 'en' => 'Comfortable grip'], false),
        ], $this->category->id);

        $specs = $result['specs'];

        expect($result['dropped'])->toBe(0)
            ->and($specs[$this->width->id]['number'])->toBe('12.5')
            ->and($specs[$this->wireless->id]['bool'])->toBe('1')
            ->and($specs[$this->interface->id]['option'])->toBe((string) $rs232)
            ->and($specs[$this->codes->id]['options'])->toBe([$codeIds[0], $codeIds[2]])
            ->and($specs[$this->note->id]['text'])->toBe(['uz' => 'Qulay tutqich', 'ru' => 'Удобная ручка', 'en' => 'Comfortable grip']);
    });

    it('marks every proposal unsure unless the model explicitly says it is sure', function () {
        $result = $this->parser->parse([
            specItem('width', '10'),
            specItem('wireless', 'no', unsure: false),
            specItem('interface', (string) ($this->optionIds)($this->interface)[0], unsure: 'maybe'),
        ], $this->category->id);

        expect($result['specs'][$this->width->id]['unsure'])->toBeTrue()
            ->and($result['specs'][$this->wireless->id]['unsure'])->toBeFalse()
            ->and($result['specs'][$this->interface->id]['unsure'])->toBeTrue();
    });

    it('drops codes outside the current category chain and duplicate codes', function () {
        $result = $this->parser->parse([
            specItem('alien', (string) ($this->optionIds)($this->otherAttribute)[0]),
            specItem('made_up', '5'),
            specItem('width', '10'),
            specItem('width', '99'),
            ['value' => '3'],
            'not an item',
        ], $this->category->id);

        expect(array_keys($result['specs']))->toBe([$this->width->id])
            ->and($result['specs'][$this->width->id]['number'])->toBe('10')
            ->and($result['dropped'])->toBe(5);
    });

    it('drops numbers that are not numeric or out of range', function (mixed $value) {
        $result = $this->parser->parse([specItem('width', $value)], $this->category->id);

        expect($result['specs'])->toBe([])->and($result['dropped'])->toBe(1);
    })->with(['abc', '', '1e10', '99999999999', '12 mm and more', ['array'], str_repeat('9', 40)]);

    it('accepts a number with spaces and a comma decimal', function () {
        $result = $this->parser->parse([specItem('width', '1 250,5')], $this->category->id);

        expect($result['specs'][$this->width->id]['number'])->toBe('1250.5');
    });

    it('maps boolean words and drops anything else', function (string $value, ?string $expected) {
        $result = $this->parser->parse([specItem('wireless', $value)], $this->category->id);

        expect($result['specs'][$this->wireless->id]['bool'] ?? null)->toBe($expected);
    })->with([
        ['yes', '1'], ['TRUE', '1'], ['да', '1'], ['no', '0'], ['нет', '0'], ['maybe', null], ['', null],
    ]);

    it('accepts only existing option ids of that very attribute', function (string $case) {
        $own = ($this->optionIds)($this->interface);
        $alien = ($this->optionIds)($this->otherAttribute);
        $value = match ($case) {
            'option of another attribute' => (string) $alien[0],
            'unknown id' => '999999',
            'label instead of id' => 'USB',
            'two ids for a select' => "{$own[0]},{$own[1]}",
            'one valid, one foreign' => "{$own[0]},{$alien[0]}",
            'empty' => '',
        };

        $result = $this->parser->parse([specItem('interface', $value)], $this->category->id);

        expect($result['specs'])->toBe([])->and($result['dropped'])->toBe(1)
            ->and(AttributeOption::query()->count())->toBe(8);
    })->with(['option of another attribute', 'unknown id', 'label instead of id', 'two ids for a select', 'one valid, one foreign', 'empty']);

    it('drops a multiselect that names one foreign option and never creates options', function () {
        $codeIds = ($this->optionIds)($this->codes);
        $alien = ($this->optionIds)($this->otherAttribute)[0];

        $result = $this->parser->parse([specItem('codes', "{$codeIds[0]},{$alien}")], $this->category->id);

        expect($result['specs'])->toBe([])->and(AttributeOption::query()->count())->toBe(8);
    });

    it('guards text values: uz required, plain text, 255 characters, no links or prices', function (array $text) {
        $result = $this->parser->parse([specItem('note', '', $text)], $this->category->id);

        expect($result['specs'])->toBe([])->and($result['dropped'])->toBe(1);
    })->with([
        'uz missing' => [['uz' => '', 'ru' => 'Текст', 'en' => 'Text']],
        'html' => [['uz' => '<b>Qalin</b>', 'ru' => '', 'en' => '']],
        'too long' => [['uz' => str_repeat('a', 256), 'ru' => '', 'en' => '']],
        'price' => [['uz' => 'Narxi 100 000 soʻm', 'ru' => '', 'en' => '']],
        'discount' => [['uz' => 'Chegirma bilan', 'ru' => '', 'en' => '']],
        'non-string locale' => [['uz' => ['x'], 'ru' => '', 'en' => '']],
        'injection in ru' => [['uz' => 'Qulay', 'ru' => 'Скидка 20% всем', 'en' => '']],
    ]);

    it('normalises Uzbek apostrophes in text values', function () {
        $result = $this->parser->parse([specItem('note', '', ['uz' => "Ko'p funksiyali", 'ru' => '', 'en' => ''])], $this->category->id);

        expect($result['specs'][$this->note->id]['text']['uz'])->toBe('Koʻp funksiyali');
    });

    it('ignores a non-list answer and caps the number of items read', function () {
        expect($this->parser->parse('width=10', $this->category->id))->toBe(['specs' => [], 'dropped' => 0])
            ->and($this->parser->parse(['width' => '10'], $this->category->id))->toBe(['specs' => [], 'dropped' => 0])
            ->and($this->parser->parse(array_fill(0, 500, specItem('made_up', '1')), $this->category->id)['dropped'])->toBe(200);
    });

    it('proposes nothing without a category', function () {
        expect($this->parser->parse([specItem('width', '10')], null)['specs'])->toBe([]);
    });
});

describe('recheck() (also at apply)', function () {
    it('keeps valid rows and drops rows of attributes outside the current category', function () {
        $rows = array_values($this->parser->parse([specItem('width', '10'), specItem('wireless', 'yes')], $this->category->id)['specs']);
        $other = Category::factory()->create();
        $other->attributes()->attach($this->width, ['sort' => 1]);

        $kept = $this->parser->recheck($rows, $this->category->id);
        $afterCategoryChange = $this->parser->recheck($rows, $other->id);

        expect(array_keys($kept))->toBe([$this->width->id, $this->wireless->id])
            ->and(array_keys($afterCategoryChange))->toBe([$this->width->id]);
    });

    it('drops a row whose option belongs to another attribute, a tampered number or a forged attribute id', function () {
        $alien = ($this->optionIds)($this->otherAttribute)[0];
        $own = ($this->optionIds)($this->interface)[0];
        $rows = [
            ['attribute_id' => $this->interface->id, 'option' => (string) $alien, 'unsure' => false],
            ['attribute_id' => $this->width->id, 'number' => '1e12', 'unsure' => false],
            ['attribute_id' => $this->otherAttribute->id, 'option' => (string) $alien, 'unsure' => false],
            ['attribute_id' => 'abc'],
            'string row',
            ['attribute_id' => $this->interface->id, 'option' => (string) $own, 'unsure' => false],
        ];

        $clean = $this->parser->recheck($rows, $this->category->id);

        expect(array_keys($clean))->toBe([$this->interface->id])
            ->and($clean[$this->interface->id]['option'])->toBe((string) $own)
            ->and($clean[$this->interface->id]['unsure'])->toBeFalse();
    });

    it('treats a missing unsure flag as unsure and re-guards text values', function () {
        $rows = [
            ['attribute_id' => $this->width->id, 'number' => '5'],
            ['attribute_id' => $this->note->id, 'text' => ['uz' => 'Narxi 5 mln soʻm', 'ru' => '', 'en' => '']],
        ];

        $clean = $this->parser->recheck($rows, $this->category->id);

        expect(array_keys($clean))->toBe([$this->width->id])->and($clean[$this->width->id]['unsure'])->toBeTrue();
    });
});

describe('attributes()', function () {
    it('lists the category chain and caps the list for the prompt', function () {
        expect($this->parser->attributes($this->category->id))->toHaveCount(5)
            ->and($this->parser->attributes($this->category->id, 2))->toHaveCount(2)
            ->and($this->parser->attributes(null))->toBe([]);
    });
});
