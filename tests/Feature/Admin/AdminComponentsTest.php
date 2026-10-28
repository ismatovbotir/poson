<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

function renderWithErrors(string $template, array $errors = []): string
{
    $bag = (new ViewErrorBag)->put('default', new MessageBag($errors));
    view()->share('errors', $bag);

    return Blade::render($template);
}

it('marks locale tabs with errors and opens the first one', function () {
    $html = renderWithErrors(<<<'BLADE'
        <x-admin.locale-tabs :error-keys="['form.name']">
            @foreach (config('app.supported_locales') as $locale)
                <x-admin.locale-panel :locale="$locale">
                    <x-admin.input wire:model="form.name.{{ $locale }}" label="Name" />
                </x-admin.locale-panel>
            @endforeach
        </x-admin.locale-tabs>
        BLADE, ['form.name.ru' => ['Required']]);

    expect($html)
        ->toContain('role="tablist"')
        ->toContain('x-data="{ locale: \'ru\' }"')
        ->toContain('aria-invalid="true"')
        ->toContain('Required')
        ->and(substr_count($html, 'bg-danger'))->toBe(1);
});

it('renders an accessible input with its error', function () {
    $html = renderWithErrors('<x-admin.input wire:model="form.sku" label="SKU" required hint="Unique" />', ['form.sku' => ['Taken']]);

    expect($html)
        ->toContain('for="f-form-sku"')
        ->toContain('id="f-form-sku"')
        ->toContain('aria-describedby="f-form-sku-hint f-form-sku-error"')
        ->toContain('Taken');
});

it('renders the money input in whole soʻm', function () {
    expect(renderWithErrors('<x-admin.money-input wire:model="form.price" label="Price" />'))
        ->toContain('inputmode="numeric"')
        ->toContain('soʻm');
});

it('maps badge colors', function () {
    expect(Blade::render('<x-admin.badge color="ink">Admin</x-admin.badge>'))->toContain('bg-slate-900');
});
