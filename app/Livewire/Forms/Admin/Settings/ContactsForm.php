<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Enums\PhoneLabel;
use App\Rules\UzPhone;
use App\Support\CompanySettings;
use App\Support\Phone;
use App\Support\Settings\SettingsFormat;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Contacts tab: name, phones (1–5, labelled, one primary), email, address,
 * city, coordinates, working hours text and machine-readable opening hours.
 */
class ContactsForm extends SettingsTabForm
{
    public const MAX_PHONES = 5;

    public const MAX_OPENING_HOURS = 7;

    /** @var array<string, string> */
    public array $name = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var list<array{label: string, number: string, site: bool}> */
    public array $phones = [];

    /** Index of the primary phone in $phones. */
    public int $primary = 0;

    public string $email = '';

    /** @var array<string, string> */
    public array $address = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $city = ['uz' => '', 'ru' => '', 'en' => ''];

    public string $lat = '';

    public string $lng = '';

    /** @var array<string, string> */
    public array $working_hours = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var list<array{days: list<string>, opens: string, closes: string}> */
    public array $opening_hours = [];

    public function fillFrom(CompanySettings $settings): void
    {
        $this->name = static::translation($settings->get('name'));
        $this->phones = [];
        $this->primary = 0;

        foreach (array_values((array) $settings->get('phones', [])) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['primary'] ?? false) === true) {
                $this->primary = count($this->phones);
            }

            $number = is_string($row['number'] ?? null) ? $row['number'] : '';

            $this->phones[] = [
                'label' => PhoneLabel::tryFrom((string) ($row['label'] ?? ''))?->value ?? PhoneLabel::Office->value,
                'number' => Phone::normalize($number) !== null ? Phone::display($number) : $number,
                'site' => ($row['site'] ?? true) === true,
            ];
        }

        if ($this->phones === []) {
            $this->addPhone();
        }

        $this->email = (string) (is_string($settings->get('email')) ? $settings->get('email') : '');
        $this->address = static::translation($settings->get('address'));
        $this->city = static::translation($settings->get('city'));
        $geo = $settings->get('geo');
        $this->lat = is_array($geo) && is_scalar($geo['lat'] ?? null) ? (string) $geo['lat'] : '';
        $this->lng = is_array($geo) && is_scalar($geo['lng'] ?? null) ? (string) $geo['lng'] : '';
        $this->working_hours = static::translation($settings->get('working_hours'));
        $this->opening_hours = [];

        foreach ((array) $settings->get('opening_hours', []) as $row) {
            if (is_array($row)) {
                $this->opening_hours[] = [
                    'days' => array_values(array_filter((array) ($row['days'] ?? []), 'is_string')),
                    'opens' => is_string($row['opens'] ?? null) ? $row['opens'] : '',
                    'closes' => is_string($row['closes'] ?? null) ? $row['closes'] : '',
                ];
            }
        }
    }

    public function addPhone(): void
    {
        if (count($this->phones) < self::MAX_PHONES) {
            $this->phones[] = ['label' => PhoneLabel::Orders->value, 'number' => '', 'site' => true];
        }
    }

    public function removePhone(int $index): void
    {
        if (count($this->phones) <= 1 || ! isset($this->phones[$index])) {
            return;
        }

        array_splice($this->phones, $index, 1);

        if ($this->primary === $index) {
            $this->primary = 0;
        } elseif ($this->primary > $index) {
            $this->primary--;
        }
    }

    public function addOpeningHours(): void
    {
        if (count($this->opening_hours) < self::MAX_OPENING_HOURS) {
            $this->opening_hours[] = ['days' => [], 'opens' => '09:00', 'closes' => '18:00'];
        }
    }

    public function removeOpeningHours(int $index): void
    {
        if (isset($this->opening_hours[$index])) {
            array_splice($this->opening_hours, $index, 1);
        }
    }

    public function normalize(): void
    {
        $this->email = trim($this->email);
        $this->lat = str_replace(',', '.', trim($this->lat));
        $this->lng = str_replace(',', '.', trim($this->lng));

        foreach ($this->phones as $index => $phone) {
            if (is_array($phone) && is_scalar($phone['number'] ?? null)) {
                $this->phones[$index]['number'] = trim((string) $phone['number']);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            ...static::translationRules('name', 100),
            'phones' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHONES],
            'phones.*.label' => ['required', Rule::enum(PhoneLabel::class)],
            'phones.*.number' => ['required', 'string', 'max:30', new UzPhone, $this->distinctNumber(...)],
            'phones.*.site' => ['boolean'],
            'primary' => ['required', 'integer', 'min:0', 'max:'.max(0, count($this->phones) - 1)],
            'email' => ['required', 'string', 'max:150', 'email:filter'],
            ...static::translationRules('address', 255),
            ...static::translationRules('city', 100),
            'lat' => ['nullable', 'required_with:lng', SettingsFormat::rule(SettingsFormat::COORDINATE), 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', SettingsFormat::rule(SettingsFormat::COORDINATE), 'numeric', 'between:-180,180'],
            ...static::translationRules('working_hours', 100),
            'opening_hours' => ['array', 'max:'.self::MAX_OPENING_HOURS],
            'opening_hours.*.days' => ['required', 'array', 'min:1'],
            'opening_hours.*.days.*' => ['distinct', Rule::in(array_keys(SettingsFormat::DAYS))],
            'opening_hours.*.opens' => ['required', SettingsFormat::rule(SettingsFormat::TIME)],
            'opening_hours.*.closes' => ['required', SettingsFormat::rule(SettingsFormat::TIME), $this->closesAfterOpens(...)],
        ];
    }

    /**
     * The same number (after normalization) may appear only once.
     */
    protected function distinctNumber(string $attribute, mixed $value, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $number = is_string($value) ? Phone::normalize($value) : null;

        if ($number === null) {
            return;
        }

        foreach ($this->phones as $other => $phone) {
            if ($other < $index && is_array($phone) && is_string($phone['number'] ?? null) && Phone::normalize($phone['number']) === $number) {
                $fail('validation.distinct')->translate();

                return;
            }
        }
    }

    /**
     * "closes" must be later than "opens" in the same row.
     */
    protected function closesAfterOpens(string $attribute, mixed $value, Closure $fail): void
    {
        $index = (int) explode('.', $attribute)[1];
        $opens = (string) ($this->opening_hours[$index]['opens'] ?? '');

        if (is_string($value) && $opens !== '' && $value <= $opens) {
            $fail(__('admin.settings.company.errors.closes_after_opens'));
        }
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'lat.regex' => __('admin.settings.company.errors.coordinate'),
            'lng.regex' => __('admin.settings.company.errors.coordinate'),
            'opening_hours.*.opens.regex' => __('admin.settings.company.errors.time'),
            'opening_hours.*.closes.regex' => __('admin.settings.company.errors.time'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            ...static::translationAttributes('name', __('admin.settings.company.fields.name')),
            ...static::translationAttributes('address', __('admin.settings.company.fields.address')),
            ...static::translationAttributes('city', __('admin.settings.company.fields.city')),
            ...static::translationAttributes('working_hours', __('admin.settings.company.fields.working_hours')),
            'phones' => __('admin.settings.company.fields.phones'),
            'primary' => __('admin.settings.company.fields.primary_phone'),
            'email' => __('admin.settings.company.fields.email'),
            'lat' => __('admin.settings.company.fields.lat'),
            'lng' => __('admin.settings.company.fields.lng'),
            'opening_hours' => __('admin.settings.company.fields.opening_hours'),
        ];

        foreach (array_keys($this->phones) as $index) {
            $attributes["phones.{$index}.label"] = __('admin.settings.company.fields.phone_label');
            $attributes["phones.{$index}.number"] = __('admin.settings.company.fields.phone_number');
        }

        foreach (array_keys($this->opening_hours) as $index) {
            $attributes["opening_hours.{$index}.days"] = __('admin.settings.company.fields.days');
            $attributes["opening_hours.{$index}.opens"] = __('admin.settings.company.fields.opens');
            $attributes["opening_hours.{$index}.closes"] = __('admin.settings.company.fields.closes');
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $phones = [];

        foreach (array_values($this->phones) as $index => $phone) {
            $phones[] = [
                'label' => (string) $phone['label'],
                'number' => (string) Phone::normalize((string) $phone['number']),
                'primary' => $index === $this->primary,
                'site' => $index === $this->primary || (bool) ($phone['site'] ?? false),
            ];
        }

        $order = array_keys(SettingsFormat::DAYS);
        $openingHours = [];

        foreach ($this->opening_hours as $row) {
            $openingHours[] = [
                'days' => array_values(array_intersect($order, (array) $row['days'])),
                'opens' => (string) $row['opens'],
                'closes' => (string) $row['closes'],
            ];
        }

        return [
            'name' => static::trimTranslation($this->name),
            'phones' => $phones,
            'email' => $this->email,
            'address' => static::trimTranslation($this->address),
            'city' => static::trimTranslation($this->city),
            'geo' => $this->lat !== '' && $this->lng !== '' ? ['lat' => (float) $this->lat, 'lng' => (float) $this->lng] : null,
            'working_hours' => static::trimTranslation($this->working_hours),
            'opening_hours' => $openingHours,
        ];
    }
}
