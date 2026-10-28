<?php

use App\Enums\Role;
use App\Livewire\Admin\Ai\Assistant;
use App\Livewire\Admin\Articles\Form as ArticleForm;
use App\Livewire\Admin\Banners\Form as BannerForm;
use App\Livewire\Admin\Brands\Form as BrandForm;
use App\Livewire\Admin\News\Form as NewsForm;
use App\Livewire\Admin\Products\Images;
use App\Models\AiPromptOverride;
use App\Models\Attribute;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 12 task 4: AI buttons and contexts of brands (SEO), articles vs
 * news, banners and product image alts; assistant subjects; overrides as
 * the filled-in hint.
 */
beforeEach(function () {
    enableAiAssist();
    fakeTextAssistant();
    $this->actingAs($this->user = staffUser(Role::ContentManager));
});

/**
 * A service engineer who may use AI (ai.use given directly), for the
 * "no update rights on the product" cases.
 */
function aiServiceEngineer(): User
{
    $user = staffUser(Role::ServiceEngineer);
    $user->givePermissionTo('ai.use');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

describe('articles and news', function () {
    it('uses the article.* fields on the article screen and news.* on the news screen, without titles', function () {
        $articleHtml = $this->get(route('admin.articles.create'))->assertOk()->getContent();
        $newsHtml = $this->get(route('admin.news.create'))->assertOk()->getContent();

        foreach (['excerpt', 'body', 'meta_title', 'meta_description'] as $field) {
            expect($articleHtml)->toContain('data-ai-button="article.'.$field.'"')
                ->not->toContain('data-ai-button="news.'.$field.'"')
                ->and($newsHtml)->toContain('data-ai-button="news.'.$field.'"')
                ->not->toContain('data-ai-button="article.'.$field.'"');
        }

        expect($articleHtml)->not->toContain('data-ai-button="news.title"')->not->toContain('data-ai-button="article.title"');
    });

    it('opens the article fields with the article context and applies only article texts', function () {
        $article = NewsPost::factory()->article()->create();

        $component = Livewire::test(ArticleForm::class, ['newsPost' => $article])
            ->call('openAi', 'form.body', 'ru')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'article.body'
                && $params['context']['subject_type'] === 'news_post'
                && $params['context']['subject_id'] === $article->id
                && str_contains($params['context']['category'], 'knowledge article'));

        $component->call('applyAi', $component->id(), 'form.excerpt', 'news.excerpt', ['uz' => 'News text'])
            ->assertSet('form.excerpt.uz', (string) $article->getTranslation('excerpt', 'uz', false))
            ->call('applyAi', $component->id(), 'form.excerpt', 'article.excerpt', ['uz' => 'Qoʻllanma qisqacha'])
            ->assertSet('form.excerpt.uz', 'Qoʻllanma qisqacha');
    });

    it('opens the news fields from the news screen', function () {
        Livewire::test(NewsForm::class)
            ->call('openAi', 'form.excerpt', 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'news.excerpt');
    });

    it('keeps a news_post subject only for the field of its kind', function () {
        $article = NewsPost::factory()->article()->create();
        $news = NewsPost::factory()->create();

        $subject = fn (string $field, NewsPost $post) => Livewire::test(Assistant::class)
            ->call('openFor', 'form-1', 'form.excerpt', $field, 'uz', ['name' => 'X', 'subject_type' => 'news_post', 'subject_id' => $post->id], [])
            ->get('context')['subject_id'];

        expect($subject('article.excerpt', $article))->toBe($article->id)
            ->and($subject('article.excerpt', $news))->toBeNull()
            ->and($subject('news.excerpt', $news))->toBe($news->id)
            ->and($subject('news.excerpt', $article))->toBeNull();
    });
});

describe('brands', function () {
    it('shows the SEO card with AI buttons for the meta fields', function () {
        $brand = Brand::factory()->create();

        $this->get(route('admin.brands.edit', $brand))
            ->assertOk()
            ->assertSee('data-brand-seo', false)
            ->assertSee('data-ai-button="brand.meta_title"', false)
            ->assertSee('data-ai-button="brand.meta_description"', false)
            ->assertSee('data-ai-button="brand.description"', false);
    });

    it('opens the brand meta fields with the brand context: website, description and categories of active products', function () {
        $brand = Brand::factory()->create(['name' => 'Zebra', 'website' => 'https://www.zebra.com']);
        $printers = Category::factory()->named(['uz' => 'Printerlar', 'en' => 'Label printers'])->create();
        $scanners = Category::factory()->named(['uz' => 'Skanerlar', 'en' => 'Barcode scanners'])->create();
        $hidden = Category::factory()->named(['uz' => 'Arxiv', 'en' => 'Archive'])->create();
        Product::factory()->count(2)->inCategory($printers)->create(['brand_id' => $brand->id, 'price' => 99_000_000]);
        Product::factory()->inCategory($scanners)->create(['brand_id' => $brand->id]);
        Product::factory()->inactive()->inCategory($hidden)->create(['brand_id' => $brand->id]);

        Livewire::test(BrandForm::class, ['brand' => $brand])
            ->set('form.description.ru', '<p>Принтеры и <strong>сканеры</strong></p>')
            ->call('openAi', 'form.meta_description', 'ru')
            ->assertDispatched('ai-open', function (string $name, array $params) use ($brand): bool {
                $facts = $params['context']['facts'];
                $categories = explode('; ', $facts['Equipment categories'] ?? '');
                sort($categories);

                return $params['field'] === 'brand.meta_description'
                    && $params['context']['name'] === 'Zebra'
                    && $facts['Website'] === 'https://www.zebra.com'
                    && $facts['Current description (ru)'] === 'Принтеры и сканеры'
                    && $categories === ['Barcode scanners', 'Label printers']
                    && $params['context']['subject_type'] === 'brand'
                    && $params['context']['subject_id'] === $brand->id
                    && ! str_contains(json_encode($params['context']), '990000');
            });
    });

    it('fills the meta fields from the assistant', function () {
        $component = Livewire::test(BrandForm::class, ['brand' => Brand::factory()->create()]);

        $component->call('applyAi', $component->id(), 'form.meta_title', 'brand.meta_title', ['ru' => 'Zebra — подбор и сервис | Poson'])
            ->assertSet('form.meta_title.ru', 'Zebra — подбор и сервис | Poson')
            ->call('applyAi', $component->id(), 'form.meta_title', 'brand.description', ['uz' => 'x'])
            ->assertSet('form.meta_title.uz', '');
    });
});

describe('banners', function () {
    it('shows AI buttons for the title and the text only', function () {
        $html = $this->get(route('admin.banners.create'))->assertOk()->getContent();

        expect($html)->toContain('data-ai-button="banner.title"')
            ->toContain('data-ai-button="banner.text"')
            ->not->toContain('data-ai-button="banner.button_label"');
    });

    it('opens the banner fields with the placement, category path and link', function () {
        $category = Category::factory()->named(['uz' => 'Skanerlar', 'en' => 'Scanners'])->create();
        $banner = Banner::factory()->category($category)->create(['title' => ['uz' => 'Skanerlar sozlash bilan'], 'link' => '/catalog/scanners']);

        Livewire::test(BannerForm::class, ['banner' => $banner])
            ->call('openAi', 'form.text', 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'banner.text'
                && $params['context']['name'] === 'Skanerlar sozlash bilan'
                && $params['context']['category'] === 'Scanners'
                && $params['context']['facts']['Placement'] === 'Banner at the top of a catalog category page'
                && $params['context']['facts']['Button leads to'] === '/catalog/scanners'
                && $params['context']['subject_type'] === 'banner'
                && $params['context']['subject_id'] === $banner->id);

        Livewire::test(BannerForm::class)
            ->set('form.link', 'https://www.zebra.com/uz/printers?ref=x')
            ->call('openAi', 'form.title', 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'banner.title'
                && $params['context']['category'] === ''
                && $params['context']['facts']['Placement'] === 'Home page slider'
                && $params['context']['facts']['Button leads to'] === 'external site www.zebra.com'
                && $params['context']['subject_type'] === null);
    });

    it('applies the title and text, never the button label', function () {
        $component = Livewire::test(BannerForm::class);

        $component->call('applyAi', $component->id(), 'form.title', 'banner.title', ['uz' => 'Rasmiy kafolat va servis'])
            ->assertSet('form.title.uz', 'Rasmiy kafolat va servis')
            ->call('applyAi', $component->id(), 'form.button_label', 'banner.title', ['uz' => 'Batafsil'])
            ->assertSet('form.button_label.uz', '');
    });

    it('keeps a banner subject only for banner fields and users who may view banners', function () {
        $banner = Banner::factory()->create();

        $subject = fn (string $field) => Livewire::test(Assistant::class)
            ->call('openFor', 'form-1', 'form.title', $field, 'uz', ['name' => 'X', 'subject_type' => 'banner', 'subject_id' => $banner->id], [])
            ->get('context')['subject_type'];

        expect($subject('banner.title'))->toBe('banner')
            ->and($subject('product.meta_title'))->toBeNull();

        $this->actingAs(aiServiceEngineer());

        expect($subject('banner.title'))->toBeNull();
    });
});

describe('product image alts', function () {
    beforeEach(function () {
        Storage::fake('public');
        Storage::fake('local');
    });

    it('opens the alt of one own image with the product, its saved specs and the image position', function () {
        $brand = Brand::factory()->create(['name' => 'Zebra']);
        $product = Product::factory()->create(['brand_id' => $brand->id, 'model' => 'ZD421', 'name' => ['uz' => 'Zebra ZD421 printeri'], 'price' => 125_000_000]);
        $first = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
        $second = $product->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection(Product::IMAGES);
        $resolution = Attribute::factory()->number('dpi')->create(['name' => ['uz' => 'Ruxsat', 'en' => 'Print resolution']]);
        $skipped = Attribute::factory()->number()->create(['name' => ['uz' => 'Kenglik', 'en' => 'Width']]);
        ProductAttributeValue::factory()->for($product)->for($resolution)->number(203)->create();
        ProductAttributeValue::factory()->for($product)->for($skipped)->number(80)->create();
        ProductAttributeSkip::factory()->for($product)->create(['attribute_id' => $skipped->id]);

        $component = Livewire::test(Images::class, ['product' => $product])
            ->assertSeeHtml('data-ai-button="product.image_alt"');

        $component->call('openAi', "alts.{$second->id}", 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'product.image_alt'
                && $params['target'] === "alts.{$second->id}"
                && $params['context']['brand'] === 'Zebra'
                && $params['context']['facts']['Image'] === '2 of 2'
                && $params['context']['specs'] === '- Print resolution: 203 dpi'
                && $params['context']['subject_id'] === $product->id
                && ! str_contains(json_encode($params['context']), '1250000'));

        Livewire::test(Images::class, ['product' => $product])
            ->call('openAi', "alts.{$first->id}", 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['context']['facts']['Image'] === '1 of 2 (main image)');
    });

    it('fills the alt inputs on apply without saving them', function () {
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);

        $component = Livewire::test(Images::class, ['product' => $product]);
        $component->call('applyAi', $component->id(), "alts.{$media->id}", 'product.image_alt', ['uz' => 'Zebra ZD421 yorliq printeri', 'ru' => 'Принтер этикеток Zebra ZD421'])
            ->assertSet("alts.{$media->id}.uz", 'Zebra ZD421 yorliq printeri')
            ->assertSet("alts.{$media->id}.ru", 'Принтер этикеток Zebra ZD421');

        expect($media->fresh()->getCustomProperty('alt'))->toBeNull();
    });

    it('ignores a media id of another product in open and apply', function () {
        $product = Product::factory()->create();
        $own = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
        $foreign = Product::factory()->create()->addMedia(UploadedFile::fake()->image('x.jpg'))->toMediaCollection(Product::IMAGES);

        $component = Livewire::test(Images::class, ['product' => $product])
            ->call('openAi', "alts.{$foreign->id}", 'uz')
            ->assertNotDispatched('ai-open');

        $component->call('applyAi', $component->id(), "alts.{$foreign->id}", 'product.image_alt', ['uz' => 'Injected'])
            ->assertSet('alts', [$own->id => ['uz' => '', 'ru' => '', 'en' => '']]);
    });

    it('offers no alt assist without update rights on the product', function () {
        $hardware = Product::factory()->create();
        $media = $hardware->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
        $software = Product::factory()->software()->create();
        $softwareMedia = $software->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection(Product::IMAGES);

        $this->actingAs(aiServiceEngineer());

        $component = Livewire::test(Images::class, ['product' => $hardware])
            ->assertDontSeeHtml('data-ai-button="product.image_alt"')
            ->call('openAi', "alts.{$media->id}", 'uz')
            ->assertNotDispatched('ai-open');

        $component->call('applyAi', $component->id(), "alts.{$media->id}", 'product.image_alt', ['uz' => 'Injected'])
            ->assertSet("alts.{$media->id}.uz", '');

        // The same user may update software products: the assist is offered there.
        Livewire::test(Images::class, ['product' => $software])
            ->call('openAi', "alts.{$softwareMedia->id}", 'uz')
            ->assertDispatched('ai-open', fn (string $name, array $params): bool => $params['field'] === 'product.image_alt');
    });
});

describe('overrides', function () {
    it('opens the assistant with the admin override as the filled-in hint', function () {
        AiPromptOverride::factory()->forField('brand.meta_title')->create(['prompt' => 'Short SEO title for {name}, ending with Poson.']);

        Livewire::test(Assistant::class)
            ->call('openFor', 'form-1', 'form.meta_title', 'brand.meta_title', 'ru', ['name' => 'Honeywell'], [])
            ->assertSet('hint', 'Short SEO title for Honeywell, ending with Poson.')
            ->set('hint', 'Edited for this request only.')
            ->call('resetHint')
            ->assertSet('hint', 'Short SEO title for Honeywell, ending with Poson.');

        expect(AiPromptOverride::query()->sole()->prompt)->toBe('Short SEO title for {name}, ending with Poson.')
            ->and(FieldRegistry::get('brand.meta_title')->hintFor(new AiContext(name: 'Zebra')))->toBe('Short SEO title for Zebra, ending with Poson.');
    });
});
