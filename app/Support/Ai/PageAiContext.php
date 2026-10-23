<?php

namespace App\Support\Ai;

use App\Livewire\Forms\Admin\Pages\PageForm;
use App\Models\Page;

/**
 * SI assist context of a static page (plan 07), from the unsaved page form
 * (fromForm) or a saved page (fromPage, e.g. for its FAQ): titles per
 * locale, the page kind (slug and template) and the current text as plain
 * text, capped. For a saved page the subject, slug and template always come
 * from the stored record, never from the form. Company facts come from the
 * system prompt, never from here; no personal or CRM data.
 *
 * refuses(): the body of a stored legal page is never written by SI,
 * whatever the client sends (checked by the Assistant and QueueTextRequest).
 */
final class PageAiContext
{
    public static function fromForm(PageForm $form, ?Page $page = null): AiContext
    {
        if ($page?->exists) {
            $page = Page::query()->find($page->id) ?? $page;

            return self::build($form->title, $form->body, $page->slug, $page->template->value, $page);
        }

        return self::build($form->title, $form->body, $form->slug, $form->template, null);
    }

    public static function fromPage(Page $page): AiContext
    {
        return self::build($page->getTranslations('title'), $page->getTranslations('body'), $page->slug, $page->template->value, $page);
    }

    /**
     * Whether the request must be refused: the page body of a stored legal
     * page (subject re-read from the database).
     */
    public static function refuses(string $fieldKey, AiContext $context): bool
    {
        if ($fieldKey !== 'page.body' || $context->subjectType !== 'page' || $context->subjectId === null) {
            return false;
        }

        $page = Page::query()->find($context->subjectId);

        return $page !== null && ! $page->allowsAiBody();
    }

    /**
     * @param  array<string, mixed>  $titles
     * @param  array<string, mixed>  $bodies
     */
    private static function build(array $titles, array $bodies, string $slug, string $template, ?Page $page): AiContext
    {
        $facts = ProductAiContext::localized('Title', $titles);
        $facts['Page'] = trim("/page/{$slug}", '/').' ('.$template.' template)';
        $facts += ProductAiContext::localized('Current text', $bodies);

        return AiContext::fromArray([
            'name' => ProductAiContext::first($titles),
            'category' => 'Website page of Poson',
            'facts' => $facts,
            'subject_type' => $page?->exists ? $page->getMorphClass() : null,
            'subject_id' => $page?->exists ? $page->id : null,
        ]);
    }
}
