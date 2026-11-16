<?php

namespace App\Support\Content\AiProduct;

/**
 * What the AI product wizard hands to CreateProductFromAiDraft /
 * ApplyAiDraftToProduct (plan 32 §2.3): the user's edited proposal. It is
 * built from Livewire state, so every action treats it as user input and
 * re-validates it (PW-14). Images are referenced only by their index in the
 * draft row's server-side list (plan change 2): "images" holds per-index
 * flags, never a path or a media id.
 *
 * @phpstan-type ImageFlags array{clean: bool, action: string, replaces: int|null, confirmed: bool}
 */
final readonly class ProductAiDraft
{
    public const TEXT_FIELDS = ['name', 'short_description', 'description', 'meta_title', 'meta_description'];

    public const IMAGE_ACTIONS = ['keep', 'add', 'replace', 'remove'];

    /**
     * @param  array<string, mixed>  $info  step 1: category_id, brand_id, model, sku, type, sale_mode, price (whole soʻm)
     * @param  array<string, array<string, string>>  $texts  field → locale → text
     * @param  array<int, array<string, mixed>>  $specs  attribute id → value slots (AiSpecParser row)
     * @param  array<int, ImageFlags>  $images  draft image index → flags
     * @param  array<int, array<string, string>>  $alts  draft image index → locale → alt
     * @param  list<string>  $accepted  improve: accepted keys (text field keys, "spec:{id}", "alts", "images", "info:{key}")
     */
    public function __construct(
        public string $mode,
        public ?int $productId,
        public array $info = [],
        public array $texts = [],
        public array $specs = [],
        public array $images = [],
        public array $alts = [],
        public ?int $main = null,
        public array $accepted = [],
    ) {}

    public function accepts(string $key): bool
    {
        return in_array($key, $this->accepted, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'product_id' => $this->productId,
            'info' => $this->info,
            'texts' => $this->texts,
            'specs' => $this->specs,
            'images' => $this->images,
            'alts' => $this->alts,
            'main' => $this->main,
            'accepted' => $this->accepted,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $texts = [];

        foreach (self::TEXT_FIELDS as $field) {
            foreach (config('app.supported_locales') as $locale) {
                $value = $data['texts'][$field][$locale] ?? null;

                if (is_string($value)) {
                    $texts[$field][$locale] = $value;
                }
            }
        }

        $images = [];

        foreach (is_array($data['images'] ?? null) ? $data['images'] : [] as $index => $flags) {
            if (! is_int($index) || ! is_array($flags)) {
                continue;
            }

            $action = is_string($flags['action'] ?? null) && in_array($flags['action'], self::IMAGE_ACTIONS, true) ? $flags['action'] : 'keep';
            $replaces = filter_var($flags['replaces'] ?? null, FILTER_VALIDATE_INT);

            $images[$index] = [
                'clean' => (bool) ($flags['clean'] ?? false),
                'action' => $action,
                'replaces' => $replaces === false ? null : $replaces,
                'confirmed' => (bool) ($flags['confirmed'] ?? false),
            ];
        }

        $alts = [];

        foreach (is_array($data['alts'] ?? null) ? $data['alts'] : [] as $index => $locales) {
            if (! is_int($index) || ! is_array($locales)) {
                continue;
            }

            foreach (config('app.supported_locales') as $locale) {
                if (is_string($locales[$locale] ?? null)) {
                    $alts[$index][$locale] = $locales[$locale];
                }
            }
        }

        $main = filter_var($data['main'] ?? null, FILTER_VALIDATE_INT);
        $productId = filter_var($data['product_id'] ?? null, FILTER_VALIDATE_INT);

        return new self(
            mode: is_string($data['mode'] ?? null) ? $data['mode'] : '',
            productId: $productId === false ? null : $productId,
            info: is_array($data['info'] ?? null) ? $data['info'] : [],
            texts: $texts,
            specs: is_array($data['specs'] ?? null) ? array_filter($data['specs'], 'is_array') : [],
            images: $images,
            alts: $alts,
            main: $main === false ? null : $main,
            accepted: array_values(array_filter(is_array($data['accepted'] ?? null) ? $data['accepted'] : [], 'is_string')),
        );
    }
}
