<?php

use App\Actions\Catalog\AddProductImages;
use App\Enums\AiDraftStatus;
use App\Enums\Role;
use App\Livewire\Admin\Products\AiWizard;
use App\Models\AiProductDraft;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeChatModel;
use Tests\Support\ImageFixtures;

require_once __DIR__.'/AiWizardHelpers.php';

/**
 * Plan 32 §5, §9, PW-4, PW-6, PW-17: authorization of the wizard per role on
 * every Livewire method, upload hooks and the poll, draft ownership by uuid +
 * user, locked server-set properties, and tampered client input (indexes,
 * media ids, step names) that must not reach another record or a file.
 */
beforeEach(function () {
    wizStorage();
    wizOn();
    Queue::fake();
    FakeChatModel::bind();
});

/**
 * The wizard of $user opened on the photo step with a saved draft.
 */
function wizOpened(User $user, ?Product $product = null): Testable
{
    $component = $product === null
        ? Livewire::actingAs($user)->test(AiWizard::class)->set('info.category_id', (string) Category::factory()->create()->id)->set('info.sku', 'zb-'.fake()->unique()->numerify('####'))->set('info.type', 'hardware')
        : Livewire::actingAs($user)->test(AiWizard::class, ['product' => $product]);

    return $component->call('saveInfo')->assertHasNoErrors()->call('acknowledgePhotoHint');
}

/**
 * Every public wizard action a client can call, with harmless arguments.
 *
 * @return array<string, array{0: string, 1: list<mixed>}>
 */
function wizMethods(): array
{
    return [
        'saveInfo' => ['saveInfo', []],
        'acknowledgePhotoHint' => ['acknowledgePhotoHint', []],
        'toggleRemove' => ['toggleRemove', [0]],
        'toggleClean' => ['toggleClean', [0]],
        'setReplace' => ['setReplace', [0, null]],
        'setMain' => ['setMain', [0]],
        'look' => ['look', []],
        'write' => ['write', []],
        'poll' => ['poll', []],
        'cancel' => ['cancel', []],
        'startOver' => ['startOver', []],
        'goTo' => ['goTo', ['info']],
        'updatedOnlyEmpty' => ['updatedOnlyEmpty', []],
        'toConfirm' => ['toConfirm', []],
        'apply' => ['apply', []],
        '_startUpload' => ['_startUpload', ['upload', [['name' => 'a.jpg', 'size' => 100, 'type' => 'image/jpeg']], false]],
        '_finishUpload' => ['_finishUpload', ['upload', ['x'], false]],
    ];
}

describe('routes and mount', function () {
    it('lets Admin and Content manager open both routes and refuses the other roles', function (Role $role, int $status) {
        $user = staffUser($role);
        $product = Product::factory()->create();

        $this->actingAs($user)->get(route('admin.products.ai.create'))->assertStatus($status);
        $this->actingAs($user)->get(route('admin.products.ai.improve', $product))->assertStatus($status);
    })->with([
        'admin' => [Role::Admin, 200],
        'content manager' => [Role::ContentManager, 200],
        'sales manager' => [Role::SalesManager, 403],
        'service engineer' => [Role::ServiceEngineer, 403],
    ]);

    it('sends a guest to the login and answers 404 for a missing or trashed product', function () {
        $product = Product::factory()->create();
        $this->get(route('admin.products.ai.create'))->assertRedirect();

        $this->actingAs(staffUser(Role::ContentManager));
        $this->get('/admin/products/999999/ai')->assertNotFound();
        $trashed = Product::factory()->create();
        $trashed->delete();
        $this->get('/admin/products/'.$trashed->id.'/ai')->assertNotFound();
    });

    it('refuses to mount for a role without access, for a user without ai.generate and with the switch off', function () {
        Livewire::actingAs(staffUser(Role::SalesManager))->test(AiWizard::class)->assertForbidden();
        Livewire::actingAs(staffUser(Role::ServiceEngineer))->test(AiWizard::class, ['product' => Product::factory()->create()])->assertForbidden();

        $cm = staffUser(Role::ContentManager);
        wizRevokeAiGenerate();
        Livewire::actingAs($cm)->test(AiWizard::class)->assertForbidden();

        wizOn(false);
        Livewire::actingAs(staffUser(Role::Admin))->test(AiWizard::class)->assertForbidden();
    });

    it('refuses improve when the user cannot update the product', function () {
        $cm = staffUser(Role::ContentManager);
        $cm->roles->first()->revokePermissionTo(['products.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($cm)->get(route('admin.products.ai.improve', Product::factory()->create()))->assertForbidden();
    });
});

describe('every method re-authorizes (PW-6)', function () {
    it('refuses every method and upload hook to a role that lost access after mounting', function (Role $intruder) {
        $owner = staffUser(Role::ContentManager);
        $stranger = User::factory()->withRole($intruder)->create();

        foreach (wizMethods() as $name => [$method, $args]) {
            $component = wizOpened($owner);
            $this->actingAs($stranger);

            $component->call($method, ...$args)->assertForbidden();

            $this->actingAs($owner);
        }
    })->with(['sales manager' => [Role::SalesManager], 'service engineer' => [Role::ServiceEngineer]]);

    it('refuses every method to a Content manager whose ai.generate was revoked', function () {
        $owner = staffUser(Role::ContentManager);
        $components = collect(wizMethods())->map(fn (array $call): Testable => wizOpened($owner));

        wizRevokeAiGenerate();

        foreach (wizMethods() as $name => [$method, $args]) {
            $components[$name]->call($method, ...$args)->assertForbidden();
        }
    });

    it('refuses every method once the switch is turned off', function () {
        $owner = staffUser(Role::ContentManager);
        $components = collect(wizMethods())->map(fn (array $call): Testable => wizOpened($owner));

        wizOn(false);

        foreach (wizMethods() as $name => [$method, $args]) {
            $components[$name]->call($method, ...$args)->assertForbidden();
        }
    });

    it('refuses an upload, a poll and an apply of an improve wizard after update was revoked', function () {
        $cm = staffUser(Role::ContentManager);
        $product = Product::factory()->create();
        $upload = wizOpened($cm, $product);
        $poll = wizOpened($cm, $product);
        $apply = wizOpened($cm, $product);

        $cm->roles->first()->revokePermissionTo('products.update');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $upload->call('_startUpload', 'upload', [['name' => 'a.jpg', 'size' => 100, 'type' => 'image/jpeg']], false)->assertForbidden();
        $poll->call('poll')->assertForbidden();
        $apply->call('apply')->assertForbidden();

        expect(AiProductDraft::query()->sole()->input['images'])->toBe([]);
    });

    it('refuses the upload hooks without the photo-hint acknowledgement', function () {
        $user = staffUser(Role::ContentManager);
        $open = fn (): Testable => Livewire::actingAs($user)->test(AiWizard::class)
            ->set('info.category_id', (string) Category::factory()->create()->id)->set('info.sku', 'ZB-HINT')->set('info.type', 'hardware')->call('saveInfo');

        $open()->call('_startUpload', 'upload', [['name' => 'a.jpg', 'size' => 100, 'type' => 'image/jpeg']], false)->assertForbidden();
        $open()->call('_finishUpload', 'upload', ['x'], false)->assertForbidden();

        expect(AiProductDraft::query()->sole()->input['images'])->toBe([]);
    });

    it('refuses upload hooks for any property but "upload"', function () {
        $user = staffUser(Role::ContentManager);

        wizOpened($user)->call('_startUpload', 'notes', [['name' => 'a.jpg', 'size' => 100, 'type' => 'image/jpeg']], false)->assertForbidden();
        wizOpened($user)->call('_finishUpload', 'info', ['x'], false)->assertForbidden();
    });
});

describe('draft ownership', function () {
    it('answers 404 for another user\'s draft, a malformed uuid or a draft of the other mode, on the route', function () {
        $owner = staffUser(Role::ContentManager);
        $product = Product::factory()->create();
        $mine = AiProductDraft::factory()->forUser($owner)->create();
        $improving = AiProductDraft::factory()->forUser($owner)->improving($product)->create();
        $other = staffUser(Role::Admin);

        $this->actingAs($other)->get(route('admin.products.ai.create', ['draft' => $mine->uuid]))->assertNotFound();
        $this->actingAs($other)->get(route('admin.products.ai.improve', [$product, 'draft' => $improving->uuid]))->assertNotFound();
        $this->actingAs($owner)->get(route('admin.products.ai.create', ['draft' => 'not-a-uuid']))->assertNotFound();
        $this->actingAs($owner)->get(route('admin.products.ai.create', ['draft' => ['x']]))->assertNotFound();
        $this->actingAs($owner)->get(route('admin.products.ai.create', ['draft' => $improving->uuid]))->assertNotFound();
        $this->actingAs($owner)->get(route('admin.products.ai.improve', [Product::factory()->create(), 'draft' => $improving->uuid]))->assertNotFound();
        $this->actingAs($owner)->get(route('admin.products.ai.create', ['draft' => $mine->uuid]))->assertOk();
    });

    it('answers 404 for a finished draft', function (AiDraftStatus $status) {
        $owner = staffUser(Role::ContentManager);
        $draft = AiProductDraft::factory()->forUser($owner)->status($status)->create();

        $this->actingAs($owner)->get(route('admin.products.ai.create', ['draft' => $draft->uuid]))->assertNotFound();
    })->with([AiDraftStatus::Applied, AiDraftStatus::Expired]);

    it('never lets a component act on another user\'s draft', function () {
        $victim = staffUser(Role::ContentManager);
        $draft = AiProductDraft::factory()->forUser($victim)->withInfo(wizInfo())->create();
        $attacker = staffUser(Role::Admin);

        $component = Livewire::actingAs($attacker)->test(AiWizard::class);

        expect(fn () => $component->set('uuid', $draft->uuid))->toThrow('Cannot update locked property');
        $component->call('poll')->assertOk();
        $component->call('cancel')->assertOk();

        expect($draft->refresh()->status)->toBe(AiDraftStatus::Open);
    });

    it('resumes only the user\'s own open draft', function () {
        $victim = staffUser(Role::ContentManager);
        AiProductDraft::factory()->forUser($victim)->withInfo(wizInfo(null, ['sku' => 'VICTIM-1']))->create();
        $me = staffUser(Role::Admin);

        $component = Livewire::actingAs($me)->test(AiWizard::class);

        expect($component->get('uuid'))->toBeNull()->and($component->get('info.sku'))->not->toBe('VICTIM-1');
    });
});

describe('locked and tampered state', function () {
    it('keeps mode, product, draft and step server-side', function (string $property, mixed $value) {
        $component = wizOpened(staffUser(Role::ContentManager));

        expect(fn () => $component->set($property, $value))->toThrow('Cannot update locked property');
    })->with([
        ['mode', 'improve'],
        ['productId', 1],
        ['uuid', '00000000-0000-0000-0000-000000000000'],
        ['step', 'confirm'],
        ['images', [['source' => 'media', 'clean' => true, 'action' => 'remove', 'replaces' => null]]],
    ]);

    it('ignores non-integer, negative and out-of-range image indexes without a server error', function (mixed $index) {
        $component = wizOpened(staffUser(Role::ContentManager));
        $component->set('upload', UploadedFile::fake()->image('a.jpg', 300, 300));
        $before = AiProductDraft::query()->sole()->input['images'];

        foreach (['toggleRemove', 'toggleClean', 'setMain'] as $method) {
            $component->call($method, $index)->assertOk();
        }

        $component->call('setReplace', $index, $index)->assertOk();

        expect(AiProductDraft::query()->sole()->input['images'])->toBe($before);
    })->with([-1, 99, 'abc', '1; drop table', [1], null, 1.5, '']);

    it('ignores a step name that is not a wizard step and any forward jump', function (mixed $step) {
        $component = wizOpened(staffUser(Role::ContentManager));

        $component->call('goTo', $step)->assertOk()->assertSet('step', 'photos');
    })->with(['confirm', 'review', 'nope', '', 7, null, [['info']], '../etc']);

    it('rejects a hostile category, brand, type or sale mode in step 1', function (string $field, string $value) {
        $component = Livewire::actingAs(staffUser(Role::ContentManager))->test(AiWizard::class)
            ->set('info.category_id', (string) Category::factory()->create()->id)->set('info.sku', 'ZB-STEP1')->set('info.type', 'hardware')
            ->set($field, $value)->call('saveInfo');

        $component->assertHasErrors([$field])->assertSet('step', 'info');
        expect(AiProductDraft::query()->count())->toBe(0);
    })->with([
        ['info.category_id', '999999'],
        ['info.category_id', 'abc'],
        ['info.brand_id', '999999'],
        ['info.type', 'rocket'],
        ['info.sale_mode', 'free'],
        ['info.sku', ''],
        ['info.price', '-5'],
    ]);

    it('ignores a media id of another product or a setting in an improve wizard', function () {
        $mine = Product::factory()->create();
        $foreign = Product::factory()->create();
        app(AddProductImages::class)->handle($foreign, [UploadedFile::fake()->image('foreign.jpg', 400, 400)]);
        $foreignMedia = $foreign->getMedia(Product::IMAGES)->first();
        $settingMedia = Setting::query()->create(['key' => 'brand.logo', 'group' => 'company', 'value' => 'x'])
            ->addMedia(UploadedFile::fake()->image('logo.png', 100, 100))->toMediaCollection('logo');
        $cm = staffUser(Role::ContentManager);
        $component = wizOpened($cm, $mine);

        $draft = AiProductDraft::query()->sole();
        $draft->forceFill(['input' => [...$draft->input, 'images' => [['source' => 'media', 'ref' => $foreignMedia->id, 'clean' => false, 'action' => 'keep', 'replaces' => null]]]])->save();
        $component = Livewire::actingAs($cm)->test(AiWizard::class, ['product' => $mine]);

        // Clean the planted entry: the product's own collection does not contain it, so no file is made or read.
        $component->call('toggleClean', 0)->assertOk();

        expect(Storage::disk('local')->allFiles('ai-drafts'))->toBe([])
            ->and($foreignMedia->refresh()->getPath())->toBeFile()
            ->and($settingMedia->refresh())->not->toBeNull();
    });

    it('removes nothing from another product when an apply names a foreign media entry', function () {
        $mine = Product::factory()->create();
        $foreign = Product::factory()->create();
        app(AddProductImages::class)->handle($foreign, [UploadedFile::fake()->image('foreign.jpg', 400, 400)]);
        $foreignMedia = $foreign->getMedia(Product::IMAGES)->first();
        $cm = staffUser(Role::ContentManager);
        wizOpened($cm, $mine);

        $draft = AiProductDraft::query()->sole();
        $draft->forceFill([
            'status' => AiDraftStatus::Ready,
            'input' => [...$draft->input, 'images' => [['source' => 'media', 'ref' => $foreignMedia->id, 'clean' => false, 'action' => 'remove', 'replaces' => null]]],
            'result' => ['facts_confirmed' => true, 'texts' => ['short_description' => ['uz' => 'Matn', 'ru' => 'Текст', 'en' => 'Text']], 'specs' => [], 'alts' => [], 'messages' => []],
        ])->save();

        Livewire::actingAs($cm)->test(AiWizard::class, ['product' => $mine])
            ->set('confirmed', [0 => true])->set('accept.images', true)->call('toConfirm')->call('apply');

        // The apply itself ran (the short description was written), yet no foreign image was touched.
        expect($foreign->getMedia(Product::IMAGES))->toHaveCount(1)
            ->and($mine->refresh()->getTranslation('short_description', 'uz'))->toBe('Matn')
            ->and($draft->refresh()->status)->toBe(AiDraftStatus::Applied);
    });
});

describe('photo uploads (PW-1, PW-3, count limits)', function () {
    it('refuses unsupported, oversized and hostile files with a field error and stores nothing', function (string $case) {
        $component = wizOpened(staffUser(Role::ContentManager));
        $dir = sys_get_temp_dir();
        $file = match ($case) {
            'pdf' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'gif as jpg' => UploadedFile::fake()->createWithContent('photo.jpg', (string) file_get_contents(ImageFixtures::gif($dir.'/wiz-'.uniqid().'.jpg'))),
            'php as jpg' => UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET["c"]);'),
            '4001 px' => UploadedFile::fake()->image('wide.jpg', 4001, 100),
            'over 5 MB' => UploadedFile::fake()->image('heavy.jpg', 200, 200)->size(6000),
        };

        $component->set('upload', $file)->assertHasErrors(['upload']);

        expect(AiProductDraft::query()->sole()->input['images'])->toBe([])->and(Storage::disk('local')->allFiles('ai-drafts'))->toBe([]);
    })->with(['pdf', 'svg', 'gif as jpg', 'php as jpg', '4001 px', 'over 5 MB']);

    it('re-encodes a JPEG/PHP polyglot so neither stored copy carries the payload', function () {
        $user = staffUser(Role::ContentManager);
        $component = wizOpened($user);
        $path = ImageFixtures::polyglot(sys_get_temp_dir().'/wiz-polyglot-'.uniqid().'.jpg');

        $component->set('upload', UploadedFile::fake()->createWithContent('photo.jpg', (string) file_get_contents($path)))->assertHasNoErrors();

        $files = Storage::disk('local')->allFiles('ai-drafts');

        // clean/u1-plain + clean/u1-clean and their two 400 px tile previews (N1).
        expect($files)->toHaveCount(4)->and(array_filter($files, fn (string $file): bool => str_contains($file, '/preview/')))->toHaveCount(2);

        foreach ($files as $file) {
            expect(Storage::disk('local')->get($file))->not->toContain('<?php')->not->toContain('<script');
        }

        @unlink($path);
    });

    it('accepts six photos and refuses the seventh', function () {
        $component = wizOpened(staffUser(Role::ContentManager));

        foreach (range(1, 6) as $n) {
            $component->set('upload', UploadedFile::fake()->image("p{$n}.jpg", 300, 300))->assertHasNoErrors();
        }

        $component->set('upload', UploadedFile::fake()->image('p7.jpg', 300, 300))->assertHasErrors(['upload']);

        expect(AiProductDraft::query()->sole()->input['images'])->toHaveCount(6);
    });

    it('refuses an upload that would exceed the total size', function () {
        config(['chatbot.ai.product.upload_total_kb' => 30]);
        $component = wizOpened(staffUser(Role::ContentManager));

        $component->set('upload', UploadedFile::fake()->image('a.jpg', 300, 300)->size(20))->assertHasNoErrors();
        $component->set('upload', UploadedFile::fake()->image('b.jpg', 300, 300)->size(20))->assertHasErrors(['upload']);

        expect(AiProductDraft::query()->sole()->input['images'])->toHaveCount(1);
    });

    it('does not show a draft file path, folder or image bytes in the page or the component state', function () {
        $component = wizOpened(staffUser(Role::ContentManager));
        $component->set('upload', UploadedFile::fake()->image('a.jpg', 300, 300));
        $draft = AiProductDraft::query()->sole();

        $html = $component->html();
        $state = json_encode($component->snapshot);

        expect($html)->toContain('data:image/jpeg;base64,')
            ->and($html)->not->toContain('ai-drafts')->not->toContain($draft->uuid)->not->toContain('storage/app')->not->toContain('a.jpg')
            ->and($state)->not->toContain('ai-drafts')->not->toContain('data:image')->not->toContain('base64')->not->toContain('storage/app');
    });
});

describe('markup (PW-17)', function () {
    it('polls only while a stage is pending', function () {
        $user = staffUser(Role::ContentManager);
        $component = wizOpened($user);

        expect($component->html())->not->toContain('wire:poll');

        $draft = AiProductDraft::query()->sole();
        $draft->forceFill(['status' => AiDraftStatus::Seeing])->save();
        $resumed = Livewire::actingAs($user)->test(AiWizard::class);

        expect($resumed->html())->toContain('wire:poll.2s="poll"');
    });

    it('prints proposals, facts and the diff escaped and uses no raw output in the wizard views', function () {
        $user = staffUser(Role::ContentManager);
        $draft = AiProductDraft::factory()->forUser($user)->withInfo(wizInfo())
            ->withFacts(wizFacts(['brand' => '<img src=x onerror=alert(1)>']), false)
            ->withProposals(['name' => ['uz' => '<script>alert(1)</script>Zebra', 'ru' => '', 'en' => '']])->create();

        $html = Livewire::actingAs($user)->test(AiWizard::class)->html();

        expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<img src=x');

        foreach (glob(resource_path('views/livewire/admin/products/ai-wizard/*.blade.php')) as $view) {
            expect(file_get_contents($view))->not->toContain('{!!');
        }

        expect(file_get_contents(resource_path('views/livewire/admin/products/ai-wizard.blade.php')))->not->toContain('{!!');
    });
});
