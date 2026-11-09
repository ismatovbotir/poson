<?php

use App\Enums\Role;
use App\Livewire\Admin\Articles\Form as ArticleForm;
use App\Livewire\Admin\Banners\Form as BannerForm;
use App\Livewire\Admin\Brands\Form as BrandForm;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\News\Form as NewsForm;
use App\Livewire\Admin\Pages\Form as PageForm;
use App\Livewire\Admin\Products\Form as ProductForm;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * @return array{component: class-string, param: string, make: Closure(): Model, html: ?string, plain: string, max: int, save: bool}
 */
function aiCase(string $name): array
{
    return match ($name) {
        'brand' => ['component' => BrandForm::class, 'param' => 'brand', 'make' => fn () => Brand::factory()->create(), 'html' => 'description', 'plain' => 'meta_title', 'max' => 60, 'save' => true],
        'category' => ['component' => CategoryForm::class, 'param' => 'category', 'make' => fn () => Category::factory()->create(), 'html' => 'description', 'plain' => 'meta_title', 'max' => 255, 'save' => true],
        'product' => ['component' => ProductForm::class, 'param' => 'product', 'make' => fn () => Product::factory()->create(), 'html' => 'description', 'plain' => 'name', 'max' => 255, 'save' => true],
        'page' => ['component' => PageForm::class, 'param' => 'page', 'make' => fn () => Page::factory()->create(), 'html' => 'body', 'plain' => 'title', 'max' => 255, 'save' => true],
        'news' => ['component' => NewsForm::class, 'param' => 'newsPost', 'make' => fn () => NewsPost::factory()->create(), 'html' => 'body', 'plain' => 'title', 'max' => 255, 'save' => true],
        'article' => ['component' => ArticleForm::class, 'param' => 'newsPost', 'make' => fn () => NewsPost::factory()->article()->create(), 'html' => 'body', 'plain' => 'title', 'max' => 255, 'save' => true],
        'banner' => ['component' => BannerForm::class, 'param' => 'banner', 'make' => fn () => Banner::factory()->create(), 'html' => null, 'plain' => 'title', 'max' => 90, 'save' => false],
    };
}

function aiForms(bool $savable = false): array
{
    return collect(['brand', 'category', 'product', 'page', 'news', 'article', 'banner'])
        ->filter(fn (string $name): bool => ! $savable || aiCase($name)['save'])
        ->mapWithKeys(fn (string $name): array => [$name => [$name]])
        ->all();
}

beforeEach(function () {
    RateLimiter::clear('ai-fill:1');
});

it('fills the form fields from a pasted answer and saves nothing', function (string $name) {
    $case = aiCase($name);
    $record = $case['make']();
    $before = $record->fresh()->toArray();
    $count = $record::query()->count();

    $this->actingAs(staffUser());

    $answer = [$case['plain'] => ['uz' => 'Yangi matn', 'ru' => 'Новый текст', 'en' => 'New text']];

    if ($case['html']) {
        $answer[$case['html']] = ['en' => '<p>Hello <strong>world</strong></p><script>alert(1)</script>'];
    }

    $test = Livewire::test($case['component'], [$case['param'] => $record])
        ->set("form.{$case['plain']}", ['uz' => '', 'ru' => '', 'en' => ''])
        ->call('applyAiAnswer', json_encode($answer), true)
        ->assertSet("form.{$case['plain']}.uz", 'Yangi matn')
        ->assertSet("form.{$case['plain']}.ru", 'Новый текст')
        ->assertSet("form.{$case['plain']}.en", 'New text')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    if ($case['html']) {
        $test->set("form.{$case['html']}.en", '')
            ->call('applyAiAnswer', json_encode($answer))
            ->assertSet("form.{$case['html']}.en", '<p>Hello <strong>world</strong></p>');
    }

    expect($record->fresh()->toArray())->toBe($before)
        ->and($record::query()->count())->toBe($count);
})->with(aiForms());

it('reports a rejected field per locale and still fills the valid ones', function (string $name) {
    $case = aiCase($name);
    $record = $case['make']();
    $this->actingAs(staffUser());

    $answer = [$case['plain'] => [
        'uz' => str_repeat('a', $case['max'] + 1),
        'ru' => 'Скидки для всех',
        'en' => 'Good text',
    ]];

    Livewire::test($case['component'], [$case['param'] => $record])
        ->set("form.{$case['plain']}", ['uz' => '', 'ru' => '', 'en' => ''])
        ->call('applyAiAnswer', json_encode($answer))
        ->assertHasErrors(["form.{$case['plain']}.uz", "form.{$case['plain']}.ru"])
        ->assertHasNoErrors("form.{$case['plain']}.en")
        ->assertSet("form.{$case['plain']}.uz", '')
        ->assertSet("form.{$case['plain']}.ru", '')
        ->assertSet("form.{$case['plain']}.en", 'Good text')
        ->assertSet('aiReport.filled', 1);
})->with(aiForms());

it('only fills empty fields by default and overwrites when unticked', function (string $name) {
    $case = aiCase($name);
    $record = $case['make']();
    $this->actingAs(staffUser());
    $answer = json_encode([$case['plain'] => ['uz' => 'AI uz', 'ru' => 'AI ru', 'en' => 'AI en']]);

    $test = Livewire::test($case['component'], [$case['param'] => $record])
        ->set("form.{$case['plain']}", ['uz' => 'Kept uz', 'ru' => '   ', 'en' => ''])
        ->call('applyAiAnswer', $answer, true)
        ->assertSet("form.{$case['plain']}.uz", 'Kept uz')
        ->assertSet("form.{$case['plain']}.ru", 'AI ru')
        ->assertSet("form.{$case['plain']}.en", 'AI en')
        ->assertSet('aiReport.skipped', 1);

    $test->call('applyAiAnswer', $answer, false)
        ->assertSet("form.{$case['plain']}.uz", 'AI uz');
})->with(aiForms());

it('treats html that shows no text as empty', function () {
    $this->actingAs(staffUser());
    $page = Page::factory()->create();

    Livewire::test(PageForm::class, ['page' => $page])
        ->set('form.body', ['uz' => '<p></p>', 'ru' => '<p>Keep me</p>', 'en' => ''])
        ->call('applyAiAnswer', json_encode(['body' => ['uz' => '<p>One</p>', 'ru' => '<p>Two</p>']]), true)
        ->assertSet('form.body.uz', '<p>One</p>')
        ->assertSet('form.body.ru', '<p>Keep me</p>');
});

it('saves the filled values through the normal save', function (string $name) {
    $case = aiCase($name);

    $record = $case['make']();
    $this->actingAs(staffUser());

    Livewire::test($case['component'], [$case['param'] => $record])
        ->call('applyAiAnswer', json_encode([$case['plain'] => ['en' => 'Saved after AI fill']]), false)
        ->call('save')
        ->assertHasNoErrors();

    expect($record->fresh()->getTranslation($case['plain'], 'en'))->toBe('Saved after AI fill');
})->with(aiForms(savable: true));

it('still validates on save after a fill', function () {
    $this->actingAs(staffUser());
    $brand = Brand::factory()->create();

    Livewire::test(BrandForm::class, ['brand' => $brand])
        ->call('applyAiAnswer', json_encode(['meta_title' => ['en' => 'Fine']]), false)
        ->set('form.meta_title.uz', str_repeat('a', 61))
        ->call('save')
        ->assertHasErrors(['form.meta_title.uz']);

    expect($brand->fresh()->getTranslation('meta_title', 'en'))->not->toBe('Fine');
});

it('shows the box and the prompt only to writers', function (Role $role, bool $writer) {
    $brand = Brand::factory()->create();
    $this->actingAs(staffUser($role));

    $component = Livewire::test(BrandForm::class, ['brand' => $brand]);

    if ($writer) {
        $component->assertSee('data-ai-fill', false)
            ->call('toggleAiPrompt')
            ->assertSet('aiPromptOpen', true)
            ->assertSee('BEGIN DATA')
            ->assertSee('Never mention low prices');
    } else {
        $component->assertDontSee('data-ai-fill', false)
            ->call('toggleAiPrompt')->assertForbidden();
    }
})->with([
    'admin' => [Role::Admin, true],
    'content manager' => [Role::ContentManager, true],
    'sales manager' => [Role::SalesManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('gives the prompt the current texts and the record context', function () {
    $this->actingAs(staffUser());
    $product = Product::factory()->create(['sku' => 'ZD-421']);

    $component = Livewire::test(ProductForm::class, ['product' => $product])
        ->set('form.name.en', 'Zebra ZD421 label printer')
        ->call('toggleAiPrompt');

    $prompt = $component->instance()->aiPrompt;

    expect($prompt)->toContain('ZD-421')->toContain('Zebra ZD421 label printer')->toContain('- name (Name): max 255')
        ->and($prompt)->toContain((string) $product->type->value);
});

it('closes the prompt with a second toggle and keeps it empty while closed', function () {
    $this->actingAs(staffUser());

    $component = Livewire::test(BrandForm::class)
        ->assertSet('aiPromptOpen', false)
        ->call('toggleAiPrompt')
        ->assertSet('aiPromptOpen', true);

    expect($component->instance()->aiPrompt)->not->toBe('');

    $component->call('toggleAiPrompt')->assertSet('aiPromptOpen', false);

    expect($component->instance()->aiPrompt)->toBe('');
});

it('works on create screens for writers', function (string $name) {
    $case = aiCase($name);
    $this->actingAs(staffUser());

    Livewire::test($case['component'])
        ->call('applyAiAnswer', json_encode([$case['plain'] => ['uz' => 'Matn']]))
        ->assertSet("form.{$case['plain']}.uz", 'Matn');
})->with(aiForms());

it('forbids non-writers on every form that opens for them', function (string $name) {
    $case = aiCase($name);
    $record = $case['make']();
    $this->actingAs(staffUser(Role::SalesManager));

    $component = Livewire::test($case['component'], [$case['param'] => $record]);

    // Sales managers may open the catalog forms read-only; content forms are closed to them.
    if (in_array($name, ['brand', 'category', 'product'], true)) {
        $component->call('applyAiAnswer', json_encode([$case['plain'] => ['uz' => 'x']]))->assertForbidden();
    } else {
        $component->assertForbidden();
    }
})->with(aiForms());

it('throttles applies per user and minute', function () {
    $this->actingAs(staffUser());
    $brand = Brand::factory()->create();
    $answer = json_encode(['meta_title' => ['en' => 'Title']]);

    $component = Livewire::test(BrandForm::class, ['brand' => $brand]);

    foreach (range(1, 20) as $i) {
        $component->call('applyAiAnswer', $answer, false);
    }

    $component->assertSet('aiReport.filled', 1)->set('form.meta_title.en', '')->call('applyAiAnswer', $answer, false);

    $component->assertSet('form.meta_title.en', '')->assertDispatched('toast');
});

it('keeps the prompt builder limits equal to the form rules', function (string $name) {
    $case = aiCase($name);
    $this->actingAs(staffUser());

    $component = Livewire::test($case['component'], [$case['param'] => $case['make']()])->instance();
    $fields = (new ReflectionMethod($component, 'aiFields'))->invoke($component);
    $rules = (new ReflectionMethod($component->form, 'rules'))->invoke($component->form);

    expect($fields)->not->toBeEmpty();

    foreach ($fields as $field) {
        $rule = $rules["{$field->key}.*"] ?? $rules["{$field->key}.uz"] ?? null;

        expect($rule)->not->toBeNull("no rule for {$field->key}");

        $max = collect($rule)->first(fn ($item): bool => is_string($item) && str_starts_with($item, 'max:'));

        expect((int) substr((string) $max, 4))->toBe($field->max, "max of {$field->key}");
        expect(($rules["{$field->key}.uz"] ?? $rule)[0] === 'required')->toBe($field->uzRequired, "uz required of {$field->key}");
    }
})->with(aiForms());

it('builds a prompt for every form without price or code fields', function (string $name) {
    $case = aiCase($name);
    $this->actingAs(staffUser());

    $component = Livewire::test($case['component'], [$case['param'] => $case['make']()])->call('toggleAiPrompt')->instance();
    $prompt = $component->aiPrompt;

    expect($prompt)->toContain('BEGIN DATA')->toContain("- {$case['plain']} (")
        ->and($prompt)->not->toMatch('/^- (price|price_dealer|price_wholesale|slug|sku|link|website|ikpu_code) /m');
})->with(aiForms());
