<?php

namespace App\Support\Chat\Skills;

use App\Actions\Settings\UpdateCompanySettings;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\CompanySettings;

/**
 * Company facts in the chat (plan 28, CB-7): address, working hours, phone,
 * email. Only values the admin entered and that are already public in
 * llms.txt or the JSON-LD (address, phone, email, opening hours); a value
 * that is still the config placeholder is never sent.
 *
 * Rule params: {topic: address | hours | address_hours | contacts}.
 */
final class CompanyInfoSkill
{
    public const TOPICS = ['address', 'hours', 'address_hours', 'contacts'];

    public function __construct(private readonly CompanySettings $company) {}

    /**
     * @param  array<string, mixed>  $rule  Compiled rule
     */
    public function run(array $rule, ChatContext $context): ChatReply
    {
        $locale = $context->locale;
        $topic = $rule['params']['topic'] ?? null;

        $lines = match ($topic) {
            'address' => $this->address($locale),
            'hours' => $this->hours($locale),
            'address_hours' => [...$this->address($locale), ...$this->hours($locale)],
            'contacts' => $this->contacts($locale),
            default => [],
        };

        if ($lines === []) {
            return new ChatReply(
                __('chatbot.company.unavailable', [], $locale),
                [['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)]],
                $rule['id'],
            );
        }

        return new ChatReply(implode("\n", $lines), [], $rule['id']);
    }

    /**
     * @return list<string>
     */
    private function address(string $locale): array
    {
        if (! $this->isEntered('address')) {
            return [];
        }

        $address = $this->company->address($locale);

        if ($address === '') {
            return [];
        }

        $city = $this->isEntered('city') ? $this->company->city($locale) : '';

        if ($city !== '' && mb_stripos($address, $city) === false) {
            $address .= ', '.$city;
        }

        return [__('chatbot.company.address', ['value' => $address], $locale)];
    }

    /**
     * @return list<string>
     */
    private function hours(string $locale): array
    {
        if (! $this->isEntered('opening_hours')) {
            return [];
        }

        $rows = [];

        foreach ($this->company->openingHours() as $row) {
            $days = implode(', ', array_map(fn (string $day): string => __("chatbot.days.{$day}", [], $locale), $row['days']));
            $rows[] = $days.' '.$row['opens'].'–'.$row['closes'];
        }

        return $rows === [] ? [] : [__('chatbot.company.hours', ['value' => implode('; ', $rows)], $locale)];
    }

    /**
     * @return list<string>
     */
    private function contacts(string $locale): array
    {
        $lines = [];

        if ($this->isEntered('phones') && $this->company->phoneDisplay() !== '') {
            $lines[] = __('chatbot.company.phone', ['value' => $this->company->phoneDisplay()], $locale);
        }

        if ($this->isEntered('email') && $this->company->email() !== '') {
            $lines[] = __('chatbot.company.email', ['value' => $this->company->email()], $locale);
        }

        return $lines;
    }

    /**
     * Saved in admin and different from the config placeholder (same test as llms.txt).
     */
    private function isEntered(string $key): bool
    {
        return $this->company->isCustomized($key)
            && UpdateCompanySettings::canonical($this->company->get($key)) !== UpdateCompanySettings::canonical(config("company.{$key}"));
    }
}
