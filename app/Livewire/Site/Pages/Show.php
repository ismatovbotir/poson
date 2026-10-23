<?php

namespace App\Livewire\Site\Pages;

use App\Enums\PageTemplate;
use App\Models\Page;
use App\Support\HtmlSanitizer;
use App\Support\Site\FaqContent;
use App\Support\Site\SeoText;
use App\Support\Site\StructuredData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Static page (plan 07 7a) at /{locale}/page/{slug}, 404 unless active.
 * Templates (PageTemplate): default (cover image + text), contacts (company
 * contacts from Settings → Company, the click-to-load Yandex map and the
 * lead form) and legal ("Updated" date: Page::contentUpdatedAt(), the last
 * title or body change). Below the text: the page's own FAQ.
 *
 * JSON-LD: WebPage (AboutPage for "about", ContactPage for the contacts
 * template; dateModified = Page::contentUpdatedAt()), BreadcrumbList and
 * FAQPage for the page's own active items.
 * No interactivity of its own (the lead form is a child component).
 */
class Show extends Component
{
    #[Locked]
    public Page $page;

    public function mount(Page $page): void
    {
        abort_unless($page->is_active, 404);

        $this->page = $page;
    }

    public function render(StructuredData $structuredData, FaqContent $faqContent): View
    {
        $page = $this->page->loadMissing('media');
        $locale = app()->getLocale();
        $title = (string) $page->title;
        $url = route('site.page', ['page' => $page->slug]);
        $breadcrumbs = [
            ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
            ['label' => $title, 'url' => null],
        ];
        $faq = $faqContent->forPage($page);
        $cover = $page->imageUrl('large');
        $description = SeoText::description(
            $page->getTranslation('meta_description', $locale, false),
            $page->getTranslation('body', $locale),
        );

        return view('livewire.site.pages.show', [
            'title' => $title,
            'template' => $page->template,
            // Sanitized on save already; again on output as defense in depth.
            'body' => HtmlSanitizer::clean((string) $page->body),
            'cover' => $cover,
            'coverAlt' => $page->imageAlt() ?: $title,
            'breadcrumbs' => $breadcrumbs,
            'faq' => $faq,
            'updatedAt' => $page->template === PageTemplate::Legal ? $page->contentUpdatedAt() : null,
            'jsonLd' => array_values(array_filter([
                StructuredData::toScript($structuredData->webPage($this->schemaType(), $title, $url, $description, $page->contentUpdatedAt(), $cover)),
                StructuredData::toScript($structuredData->breadcrumbs($breadcrumbs, $url)),
                $faq !== [] ? StructuredData::toScript($structuredData->faqPage($faq)) : null,
            ])),
        ])
            ->title(SeoText::title($page->getTranslation('meta_title', $locale, false), $title))
            ->layoutData([
                'description' => $description,
                'image' => $cover,
            ]);
    }

    /**
     * AboutPage for "about", ContactPage for the contacts template, else WebPage.
     */
    protected function schemaType(): string
    {
        return $this->page->slug === Page::ABOUT_SLUG ? 'AboutPage' : $this->page->template->schemaType();
    }
}
