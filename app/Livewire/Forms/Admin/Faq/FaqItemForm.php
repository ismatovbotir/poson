<?php

namespace App\Livewire\Forms\Admin\Faq;

use App\Models\FaqItem;
use App\Rules\BrandSafeText;
use Livewire\Form;

/**
 * State and validation of the add / edit FAQ item editor: question and
 * plain-text answer per locale (uz required), brand-safe, plus "shown on
 * the site". Saved through SaveFaqItem.
 */
class FaqItemForm extends Form
{
    /**
     * Item being edited (null = new).
     */
    public ?int $id = null;

    /** @var array<string, string> */
    public array $question = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $answer = ['uz' => '', 'ru' => '', 'en' => ''];

    public bool $is_active = true;

    public function setItem(?FaqItem $item): void
    {
        $this->resetValidation();

        if ($item === null) {
            $this->reset('id', 'question', 'answer', 'is_active');

            return;
        }

        $this->id = $item->id;
        $this->question = $this->translations($item->getTranslations('question'));
        $this->answer = $this->translations($item->getTranslations('answer'));
        $this->is_active = $item->is_active;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = ['is_active' => ['boolean']];

        foreach (config('app.supported_locales') as $locale) {
            $required = $locale === 'uz' ? 'required' : 'nullable';
            $rules["question.{$locale}"] = [$required, 'string', 'max:'.FaqItem::QUESTION_MAX, new BrandSafeText];
            $rules["answer.{$locale}"] = [$required, 'string', 'max:'.FaqItem::ANSWER_MAX, new BrandSafeText];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach (config('app.supported_locales') as $locale) {
            $suffix = ' ('.strtoupper($locale).')';
            $attributes["question.{$locale}"] = __('admin.faq.fields.question').$suffix;
            $attributes["answer.{$locale}"] = __('admin.faq.fields.answer').$suffix;
        }

        return $attributes;
    }

    /**
     * Input for SaveFaqItem.
     *
     * @return array{question: array<string, string>, answer: array<string, string>, is_active: bool}
     */
    public function itemData(): array
    {
        return [
            'question' => $this->question,
            'answer' => $this->answer,
            'is_active' => $this->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function translations(array $values): array
    {
        return collect(config('app.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [$locale => is_string($values[$locale] ?? null) ? $values[$locale] : ''])
            ->all();
    }
}
