<?php

use App\Actions\Faq\SaveFaqItem;
use App\Enums\Role;
use App\Livewire\Admin\Faq\FaqItems;
use App\Livewire\Admin\Settings\Company;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $params
 */
function faqEditor(User $user, array $params = []): Testable
{
    return Livewire::actingAs($user)->test(FaqItems::class, $params);
}

function fillFaq(Testable $component, string $question = 'Kafolat bormi?', string $answer = "Ha, rasmiy kafolat.\nMuddat brendga bogʻliq."): Testable
{
    return $component
        ->call('create')
        ->set('form.question.uz', $question)
        ->set('form.question.ru', 'Есть гарантия?')
        ->set('form.answer.uz', $answer)
        ->set('form.answer.ru', 'Да, официальная гарантия.');
}

describe('category FAQ', function () {
    it('lets catalog writers add, edit, reorder and delete', function (Role $role) {
        $category = Category::factory()->create();
        $editor = faqEditor(staffUser($role), ['category' => $category]);

        fillFaq($editor)->call('save')->assertHasNoErrors()->assertDispatched('toast');
        fillFaq($editor, 'Yetkazib berasizmi?', 'Ha.')->call('save')->assertHasNoErrors();

        [$first, $second] = $category->faqItems()->get()->all();

        expect($first->faqable_type)->toBe($category->getMorphClass())
            ->and($first->faqable_id)->toBe($category->id)
            ->and($first->getTranslation('question', 'uz'))->toBe('Kafolat bormi?')
            ->and($first->getTranslation('answer', 'uz'))->toBe("Ha, rasmiy kafolat.\nMuddat brendga bogʻliq.")
            ->and($first->getTranslations('question'))->not->toHaveKey('en')
            ->and([$first->sort, $second->sort])->toBe([1, 2]);

        $editor->call('move', $second->id, -1);
        expect($category->faqItems()->pluck('id')->all())->toBe([$second->id, $first->id]);

        $editor->call('edit', $first->id)
            ->assertSet('form.question.uz', 'Kafolat bormi?')
            ->set('form.question.en', 'Is there a warranty?')
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        expect($first->refresh()->getTranslation('question', 'en'))->toBe('Is there a warranty?')
            ->and($first->is_active)->toBeFalse();

        $editor->call('delete', $second->id)->assertDispatched('toast');
        expect(FaqItem::query()->pluck('id')->all())->toBe([$first->id]);
    })->with(catalogWriterRoles());

    it('is read-only for view-only roles and forbids every write', function (Role $role) {
        $category = Category::factory()->create();
        $item = FaqItem::factory()->forCategory($category)->create();

        $user = staffUser($role);

        faqEditor($user, ['category' => $category])
            ->assertOk()
            ->assertSee($item->getTranslation('question', 'uz'))
            ->assertDontSee('data-faq-add', false);

        faqEditor($user, ['category' => $category])->call('create')->assertForbidden();
        faqEditor($user, ['category' => $category])->call('edit', $item->id)->assertForbidden();
        faqEditor($user, ['category' => $category])->call('move', $item->id, 1)->assertForbidden();
        faqEditor($user, ['category' => $category])->call('delete', $item->id)->assertForbidden();
        faqEditor($user, ['category' => $category])->set('form.question.uz', 'Yangi?')->set('form.answer.uz', 'Javob.')->call('save')->assertForbidden();

        expect(FaqItem::query()->count())->toBe(1);
    })->with(catalogViewOnlyRoles());

    it('shows the editor on the category form after the first save', function () {
        $admin = staffUser();
        $category = Category::factory()->create();

        $this->actingAs($admin)->get(route('admin.categories.edit', $category))
            ->assertOk()
            ->assertSeeLivewire(FaqItems::class);

        $this->actingAs($admin)->get(route('admin.categories.create'))
            ->assertOk()
            ->assertDontSeeLivewire(FaqItems::class)
            ->assertSeeText(__('admin.faq.save_first'));
    });

    it('never touches an item of another owner', function () {
        $category = Category::factory()->create();
        $foreign = FaqItem::factory()->forCategory()->create();
        $general = FaqItem::factory()->create();

        $admin = staffUser();

        foreach ([$foreign, $general] as $item) {
            faqEditor($admin, ['category' => $category])->call('delete', $item->id)->assertNotFound();
            faqEditor($admin, ['category' => $category])->call('edit', $item->id)->assertNotFound();
        }

        expect(FaqItem::query()->count())->toBe(2);
    });
});

describe('tampering', function () {
    it('returns 404 for a tampered form id on save and leaves the foreign item unchanged', function () {
        $category = Category::factory()->create();
        $foreign = FaqItem::factory()->forCategory()->create();
        $before = $foreign->getTranslations('question');

        fillFaq(faqEditor(staffUser(), ['category' => $category]))
            ->set('form.id', $foreign->id)
            ->call('save')
            ->assertNotFound();

        expect($foreign->refresh()->getTranslations('question'))->toBe($before)
            ->and($category->faqItems()->count())->toBe(0);
    });

    it('returns 404 when moving a foreign or general item', function () {
        $category = Category::factory()->create();
        FaqItem::factory()->forCategory($category)->create();
        $foreign = FaqItem::factory()->forCategory()->create(['sort' => 5]);
        $general = FaqItem::factory()->create(['sort' => 7]);
        $admin = staffUser();

        foreach ([$foreign, $general] as $item) {
            faqEditor($admin, ['category' => $category])->call('move', $item->id, -1)->assertNotFound();
        }

        expect([$foreign->refresh()->sort, $general->refresh()->sort])->toBe([5, 7]);
    });

    it('refuses to change the locked owner', function (string $property) {
        $editor = faqEditor(staffUser(), ['category' => Category::factory()->create()]);
        $other = $property === 'category' ? Category::factory()->create() : Product::factory()->create();

        expect(fn () => $editor->set($property, $other->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    })->with(['category', 'product']);

    it('denies an item whose owner is gone', function () {
        $user = staffUser(Role::ContentManager);
        $category = Category::factory()->create();
        $orphan = FaqItem::factory()->forCategory($category)->create();
        Category::query()->whereKey($category->id)->delete();

        $productItem = FaqItem::factory()->create(['faqable_type' => (new Product)->getMorphClass(), 'faqable_id' => 999_999]);

        expect($user->can('update', $orphan->refresh()))->toBeFalse()
            ->and($user->can('delete', $orphan))->toBeFalse()
            ->and($user->can('update', $productItem))->toBeFalse();
    });
});

describe('product FAQ', function () {
    it('follows the product policy: writers yes, a service engineer only on services', function () {
        $hardware = Product::factory()->create();
        $service = Product::factory()->service()->create();
        $engineer = staffUser(Role::ServiceEngineer);

        fillFaq(faqEditor($engineer, ['product' => $service]))->call('save')->assertHasNoErrors();
        faqEditor($engineer, ['product' => $hardware])->call('create')->assertForbidden();
        fillFaq(faqEditor(staffUser(Role::ContentManager), ['product' => $hardware]))->call('save')->assertHasNoErrors();
        faqEditor(staffUser(Role::SalesManager), ['product' => $hardware])->call('create')->assertForbidden();

        expect($service->faqItems()->count())->toBe(1)
            ->and($hardware->faqItems()->count())->toBe(1);
    });

    it('forbids a service engineer every write on an existing hardware item', function () {
        $hardware = Product::factory()->create();
        $item = FaqItem::factory()->forProduct($hardware)->create();
        $before = $item->getTranslations('question');
        $engineer = staffUser(Role::ServiceEngineer);
        $editor = fn () => faqEditor($engineer, ['product' => $hardware]);

        $editor()->assertOk()->assertSee($item->getTranslation('question', 'uz'));
        $editor()->call('edit', $item->id)->assertForbidden();
        $editor()->set('form.id', $item->id)->set('form.question.uz', 'Oʻzgardi?')->set('form.answer.uz', 'Ha.')->call('save')->assertForbidden();
        $editor()->call('move', $item->id, 1)->assertForbidden();
        $editor()->call('delete', $item->id)->assertForbidden();

        expect($item->refresh()->getTranslations('question'))->toBe($before)
            ->and(FaqItem::query()->count())->toBe(1);
    });

    it('shows the editor on the product form after the first save', function () {
        $admin = staffUser();
        $product = Product::factory()->create();

        $this->actingAs($admin)->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSeeLivewire(FaqItems::class)
            ->assertSee('href="#s-faq"', false);

        $this->actingAs($admin)->get(route('admin.products.create'))
            ->assertOk()
            ->assertDontSeeLivewire(FaqItems::class);
    });

    it('deletes the FAQ with the category and on permanent product delete only', function () {
        $category = Category::factory()->create();
        $product = Product::factory()->create();
        FaqItem::factory()->forCategory($category)->create();
        FaqItem::factory()->forProduct($product)->create();

        $product->delete();
        expect($product->faqItems()->count())->toBe(1);

        $product->forceDelete();
        $category->delete();

        expect(FaqItem::query()->count())->toBe(0);
    });
});

describe('general FAQ', function () {
    it('is edited by the admin on the FAQ tab of Settings → Company, without the Save button', function () {
        $admin = staffUser();

        $this->actingAs($admin)
            ->get(route('admin.settings.company', ['tab' => 'faq']))
            ->assertOk()
            ->assertSee('data-settings-tab="faq"', false)
            ->assertSeeLivewire(FaqItems::class)
            ->assertDontSeeText(__('admin.form.unsaved'));

        fillFaq(faqEditor($admin))->call('save')->assertHasNoErrors();

        expect(FaqItem::query()->sole()->isGeneral())->toBeTrue();

        Livewire::actingAs($admin)->withQueryParams(['tab' => 'faq'])->test(Company::class)
            ->call('save')
            ->assertNotFound();
    });

    it('marks active items beyond the home page limit', function () {
        foreach (range(1, 8) as $number) {
            FaqItem::factory()->create(['sort' => $number]);
        }
        FaqItem::factory()->inactive()->create(['sort' => 3]);

        $html = faqEditor(staffUser())->html();

        expect(substr_count($html, 'data-faq-beyond-home'))->toBe(2)
            ->and($html)->toContain(__('admin.faq.help_general'));
    });

    it('lists items in the admin language, falling back to uz', function () {
        FaqItem::factory()->create(['question' => ['uz' => 'Oʻzbekcha savol?', 'en' => 'English question?'], 'sort' => 1]);
        FaqItem::factory()->create(['question' => ['uz' => 'Faqat oʻzbekcha?'], 'sort' => 2]);
        app()->setLocale('en');

        faqEditor(staffUser())
            ->assertSee('English question?')
            ->assertDontSee('Oʻzbekcha savol?')
            ->assertSee('Faqat oʻzbekcha?');
    });

    it('is forbidden to every other role', function (Role $role) {
        $user = staffUser($role);
        $item = FaqItem::factory()->create();

        Livewire::actingAs($user)->test(FaqItems::class)->assertForbidden();

        expect($user->can('update', $item))->toBeFalse()
            ->and($user->can('create', [FaqItem::class, null]))->toBeFalse();
    })->with([
        'content manager' => [Role::ContentManager],
        'sales manager' => [Role::SalesManager],
        'service engineer' => [Role::ServiceEngineer],
    ]);
});

describe('validation', function () {
    it('requires the uz question and answer', function () {
        faqEditor(staffUser(), ['category' => Category::factory()->create()])
            ->call('create')
            ->set('form.question.ru', 'Только по-русски?')
            ->call('save')
            ->assertHasErrors(['form.question.uz', 'form.answer.uz']);

        expect(FaqItem::query()->count())->toBe(0);
    });

    it('rejects low-price and discount wording in every locale', function (string $field, string $text) {
        faqEditor(staffUser(), ['category' => Category::factory()->create()])
            ->call('create')
            ->set('form.question.uz', 'Savol?')
            ->set('form.answer.uz', 'Javob.')
            ->set($field, $text)
            ->call('save')
            ->assertHasErrors($field);

        expect(FaqItem::query()->count())->toBe(0);
    })->with([
        'uz question' => ['form.question.uz', 'Eng arzon skaner qaysi?'],
        'ru answer' => ['form.answer.ru', 'У нас скидки до 20%'],
        'en answer' => ['form.answer.en', 'The cheapest printers in town.'],
        'en question' => ['form.question.en', 'Do you have a sale?'],
    ]);

    it('re-checks the brand rule and the owner in the action and keeps the text verbatim', function () {
        $action = app(SaveFaqItem::class);
        $category = Category::factory()->create();

        expect(fn () => $action->handle($category, null, ['question' => ['uz' => 'Chegirma bormi?'], 'answer' => ['uz' => 'Yoʻq.'], 'is_active' => true]))
            ->toThrow(ValidationException::class);

        $item = $action->handle($category, null, [
            'question' => ['uz' => '  Skaner <40 °C da ishlaydimi? '],
            'answer' => ['uz' => "Ha, <40 °C va >-10 °C oraligʻida.\r\n\r\n\r\n\r\nIkkinchi <b>qator</b>."],
            'is_active' => true,
        ]);

        expect($item->getTranslation('question', 'uz'))->toBe('Skaner <40 °C da ishlaydimi?')
            ->and($item->getTranslation('answer', 'uz'))->toBe("Ha, <40 °C va >-10 °C oraligʻida.\n\nIkkinchi <b>qator</b>.");

        expect(fn () => $action->handle(null, $item, ['question' => ['uz' => 'Boshqa?'], 'answer' => ['uz' => 'Ha.'], 'is_active' => true]))
            ->toThrow(ValidationException::class);
    });

    it('keeps "<40 °C" through the editor and escapes it on the site', function () {
        $category = Category::factory()->create(['slug' => 'scanners']);

        faqEditor(staffUser(), ['category' => $category])
            ->call('create')
            ->set('form.question.uz', 'Sovuqda ishlaydimi?')
            ->set('form.answer.uz', 'Ha, <40 °C gacha.')
            ->call('save')
            ->assertHasNoErrors();

        expect($category->faqItems()->sole()->getTranslation('answer', 'uz'))->toBe('Ha, <40 °C gacha.');

        $this->get('/uz/catalog/scanners')->assertOk()->assertSee('Ha, &lt;40 °C gacha.', false);
    });
});
