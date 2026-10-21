<?php

use App\Actions\Settings\UpdateCompanyMedia;
use App\Actions\Settings\UpdateCompanySettings;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\CompanySettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

it('refuses keys that belong to another area', function () {
    expect(fn () => app(UpdateCompanySettings::class)->handle(SettingsArea::Contacts, ['tracking' => ['enabled' => true]], null, null))
        ->toThrow(InvalidArgumentException::class);

    expect(Setting::query()->count())->toBe(0);
});

it('audits a cleared value as cleared and ignores key order', function () {
    $action = app(UpdateCompanySettings::class);

    expect($action->handle(SettingsArea::Seo, ['verification' => ['google' => 'abcDEF123_-abcDEF123_-xyz', 'yandex' => '', 'bing' => '']], null, '10.0.0.1'))->toBe(['verification'])
        ->and($action->handle(SettingsArea::Seo, ['verification' => ['bing' => '', 'yandex' => '', 'google' => 'abcDEF123_-abcDEF123_-xyz']], null, null))->toBe([])
        ->and($action->handle(SettingsArea::Seo, ['verification' => ['google' => '', 'yandex' => '', 'bing' => '']], null, null))->toBe(['verification']);

    expect(SettingsAudit::query()->orderBy('id')->pluck('action')->all())->toBe([AuditAction::Updated, AuditAction::Cleared])
        ->and(Setting::query()->sole()->group)->toBe('seo');
});

it('refuses unknown media keys', function () {
    expect(fn () => app(UpdateCompanyMedia::class)->handle(['favicon' => ['file' => null, 'remove' => true]], null, null))->toThrow(InvalidArgumentException::class);
});

it('rolls back settings and audit rows when a write fails midway', function () {
    Setting::saving(function (Setting $setting): void {
        if ($setting->key === 'email') {
            throw new RuntimeException('disk full');
        }
    });

    expect(fn () => app(UpdateCompanySettings::class)->handle(SettingsArea::Contacts, [
        'name' => ['uz' => 'Poson Servis', 'ru' => '', 'en' => ''],
        'email' => 'new@poson.uz',
    ], null, null))->toThrow(RuntimeException::class);

    expect(Setting::query()->count())->toBe(0)
        ->and(SettingsAudit::query()->count())->toBe(0);
});

describe('media', function () {
    beforeEach(function () {
        Storage::fake('public');
        Storage::fake('local');
    });

    it('keeps the old logo when the second upload fails', function () {
        $action = app(UpdateCompanyMedia::class);
        $action->handle([Setting::LOGO => ['file' => UploadedFile::fake()->image('old.png', 300, 300), 'remove' => false]], null, null);

        $oldMedia = Setting::query()->where('key', Setting::LOGO)->sole()->getFirstMedia(Setting::LOGO);
        $oldUrl = app(CompanySettings::class)->logoUrl();
        expect($oldUrl)->not->toBe('');

        Event::listen(MediaHasBeenAddedEvent::class, function (MediaHasBeenAddedEvent $event): void {
            if ($event->media->collection_name === Setting::OG_IMAGE) {
                throw new RuntimeException('storage failed');
            }
        });

        expect(fn () => $action->handle([
            Setting::LOGO => ['file' => UploadedFile::fake()->image('new.png', 300, 300), 'remove' => false],
            Setting::OG_IMAGE => ['file' => UploadedFile::fake()->image('og.jpg', 1200, 630), 'remove' => false],
        ], null, null))->toThrow(RuntimeException::class);

        expect(Media::query()->pluck('id')->all())->toBe([$oldMedia->id])
            ->and(app(CompanySettings::class)->logoUrl())->toBe($oldUrl)
            ->and(Storage::disk('local')->allFiles())->toBe([$oldMedia->getPathRelativeToRoot()])
            ->and(SettingsAudit::query()->where('action', AuditAction::Uploaded)->count())->toBe(1);

        Storage::disk('public')->assertExists($oldMedia->getPathRelativeToRoot('logo'));
    });

    it('validates every file before writing anything', function () {
        expect(fn () => app(UpdateCompanyMedia::class)->handle([
            Setting::LOGO => ['file' => UploadedFile::fake()->image('logo.png', 300, 300), 'remove' => false],
            Setting::OG_IMAGE => ['file' => UploadedFile::fake()->image('small.jpg', 600, 300), 'remove' => false],
        ], null, null))->toThrow(ValidationException::class);

        expect(Setting::query()->count())->toBe(0)
            ->and(Media::query()->count())->toBe(0);
    });

    it('reports whether anything changed', function () {
        $action = app(UpdateCompanyMedia::class);

        expect($action->handle([Setting::LOGO => ['file' => null, 'remove' => true]], null, null))->toBeFalse()
            ->and($action->handle([Setting::LOGO => ['file' => UploadedFile::fake()->image('logo.png', 300, 300), 'remove' => false]], null, null))->toBeTrue()
            ->and($action->handle([Setting::LOGO => ['file' => null, 'remove' => true]], null, null))->toBeTrue()
            ->and(Media::query()->count())->toBe(0);
    });

    it('flushes the settings cache when a queued conversion completes', function () {
        Queue::fake();

        app(UpdateCompanyMedia::class)->handle([Setting::LOGO => ['file' => UploadedFile::fake()->image('logo.png', 300, 300), 'remove' => false]], null, null);

        expect(app(CompanySettings::class)->logoUrl())->toBe('');

        Queue::assertPushed(PerformConversionsJob::class);
        Queue::pushed(PerformConversionsJob::class)->each(fn (PerformConversionsJob $job) => app()->call([$job, 'handle']));

        expect(app(CompanySettings::class)->logoUrl())->toContain('conversions/')->toEndWith('.webp');
    });
});
