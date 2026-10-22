<?php

namespace App\Actions\Faq;

use App\Models\FaqItem;
use App\Rules\BrandSafeText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit an FAQ item of an owner (Category, Product or null = general).
 *
 * The form validates first; this action re-checks what must never be
 * stored whatever the caller: the item belongs to the owner, uz question and
 * answer are present, lengths, and the brand rule (BrandSafeText) in every
 * locale. Texts are stored verbatim (only trimmed, line breaks unified and
 * long runs of empty lines shortened): the site escapes them on output, so
 * "<40 °C" stays intact. A new item goes to the end of the owner's list
 * (max sort read under a lock, so parallel adds don't share a position).
 */
class SaveFaqItem
{
    /**
     * @param  array{question: array<string, string|null>, answer: array<string, string|null>, is_active: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(?Model $owner, ?FaqItem $item, array $data): FaqItem
    {
        if ($item !== null && ! $this->belongsTo($item, $owner)) {
            throw ValidationException::withMessages(['question.uz' => __('admin.faq.errors.not_found')]);
        }

        $question = $this->clean($data['question'] ?? []);
        $answer = $this->clean($data['answer'] ?? []);
        $this->check($question, $answer);

        return DB::transaction(function () use ($owner, $item, $question, $answer, $data): FaqItem {
            $item ??= new FaqItem([
                'faqable_type' => $owner?->getMorphClass(),
                'faqable_id' => $owner?->getKey(),
                'sort' => (int) FaqItem::query()->ownedBy($owner)->lockForUpdate()->max('sort') + 1,
            ]);

            $item->storeTranslations('question', $question);
            $item->storeTranslations('answer', $answer);
            $item->is_active = (bool) ($data['is_active'] ?? true);
            $item->save();

            return $item;
        });
    }

    private function belongsTo(FaqItem $item, ?Model $owner): bool
    {
        if ($owner === null) {
            return $item->isGeneral();
        }

        return $item->faqable_type === $owner->getMorphClass() && (int) $item->faqable_id === (int) $owner->getKey();
    }

    /**
     * Text per locale as typed: unified line breaks, at most one empty line in a row, trimmed.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private function clean(array $values): array
    {
        $clean = [];

        foreach (config('app.supported_locales') as $locale) {
            $text = str_replace(["\r\n", "\r"], "\n", (string) ($values[$locale] ?? ''));
            $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
            $clean[$locale] = trim((string) preg_replace('/[^\S\n]+/u', ' ', $text));
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $question
     * @param  array<string, string>  $answer
     *
     * @throws ValidationException
     */
    private function check(array $question, array $answer): void
    {
        $errors = [];

        foreach (['question' => [$question, FaqItem::QUESTION_MAX], 'answer' => [$answer, FaqItem::ANSWER_MAX]] as $field => [$values, $max]) {
            foreach ($values as $locale => $text) {
                $key = "{$field}.{$locale}";
                $attribute = __("admin.faq.fields.{$field}").' ('.strtoupper($locale).')';

                if ($locale === 'uz' && $text === '') {
                    $errors[$key] = __('validation.required', ['attribute' => $attribute]);
                } elseif (mb_strlen($text) > $max) {
                    $errors[$key] = __('validation.max.string', ['attribute' => $attribute, 'max' => $max]);
                } elseif (BrandSafeText::violates($text)) {
                    $errors[$key] = __('validation.brand_safe', ['attribute' => $attribute]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
