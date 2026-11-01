<?php

namespace App\Support\Catalog;

use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\RollType;

/**
 * Generated label diagram: the fallback image of a label product (pricing
 * unit label, width and height specs) that has no uploaded image. One check
 * (for()) serves the product card and the gallery.
 *
 * Every number is a server-computed float/int in a fixed viewBox; nothing
 * from the database reaches the SVG except through these typed values.
 * Sizes outside MIN_MM..MAX_MM, non-numeric or non-finite values mean null
 * (the generic placeholder is drawn instead). Needs "media", "labelSizeValues.attribute"
 * (or "attributeValues.attribute") and "activeRollTypes" loaded; returns null
 * rather than lazy loading when the size values are not loaded.
 */
final readonly class LabelDiagram
{
    public const MIN_MM = 5.0;

    public const MAX_MM = 300.0;

    /**
     * Closed server-side map of viewBox variants (side of the square viewBox and the
     * unit multiplier of the 200-unit base design). Never taken from a request.
     *
     * @var array<string, array{size: int, unit: float}>
     */
    public const VARIANTS = [
        'card' => ['size' => 200, 'unit' => 1.0],
        'gallery' => ['size' => 300, 'unit' => 1.5],
    ];

    public const DEFAULT_VARIANT = 'card';

    /** Base design is 200 x 200 units; the strip area is DRAW_H high and runs off its top and bottom edges. */
    public const BASE_SIZE = 200.0;

    public const DRAW_H = 140.0;

    private const AREA_W = 128.0;

    private const MAX_LABEL_H = 80.0;

    public const GAP = 18.0;

    private const LINER_PAD = 8.0;

    private const MIN_DRAWN = 8.0;

    private const ARROW = 4.0;

    private const ARROW_HALF = 1.5;

    /** Extension lines start this far from the label edge and overshoot the dimension line by EXT_OVERSHOOT. */
    private const EXT_GAP = 1.5;

    private const EXT_OVERSHOOT = 2.0;

    /** Dimension line distances: width line below the label (inside the gap), height line right of the liner. */
    private const WIDTH_DIM_OFFSET = 13.0;

    private const HEIGHT_DIM_OFFSET = 9.0;

    /** Space right of the liner for the height dimension (offset + value text). */
    private const DIM_ZONE_W = 21.0;

    private const HEIGHT_TEXT_OFFSET = 11.0;

    private const WIDTH_TEXT_LIFT = 3.0;

    private const MAX_CORNER = 3.0;

    /** Text lines under the strip area (baselines). */
    private const SIZE_TEXT_Y = self::DRAW_H + 23.0;

    private const CAPTION_Y = self::DRAW_H + 43.0;

    /**
     * All numbers are in the 200-unit base design (the view scales them by `unit`).
     *
     * @param  list<array{x: float, y: float}>  $labels  top-left corners (top to bottom); the outer ones are clipped by the strip area
     */
    private function __construct(
        public int $productId,
        public float $width,
        public float $height,
        public string $size,
        public ?string $caption,
        public string $variant,
        public int $viewSize,
        public array $labels,
        public float $labelW,
        public float $labelH,
        public float $cornerRadius,
        public float $linerX,
        public float $linerW,
        public float $middleY,
        public string $extensionPath,
        public string $dimensionLinePath,
        public string $arrowPath,
        public float $widthTextX,
        public float $widthTextY,
        public float $heightTextX,
        public float $heightTextY,
        public float $sizeTextY,
        public float $captionY,
    ) {}

    /** Multiplier of the 200-unit base design for this variant. */
    public function unit(): float
    {
        return $this->viewSize / self::BASE_SIZE;
    }

    public static function for(Product $product): ?self
    {
        if (! $product->isPricedPerLabel() || ! $product->relationLoaded('media') || $product->media->isNotEmpty()) {
            return null;
        }

        $values = match (true) {
            $product->relationLoaded('labelSizeValues') => $product->labelSizeValues,
            $product->relationLoaded('attributeValues') => $product->attributeValues,
            default => null,
        };

        if ($values === null) {
            return null;
        }

        $width = $height = null;

        /** @var ProductAttributeValue $value */
        foreach ($values as $value) {
            if (! $value->relationLoaded('attribute')) {
                continue;
            }

            match ($value->attribute?->code) {
                RollPricing::WIDTH_CODE => $width ??= $value->value_number,
                RollPricing::HEIGHT_CODE => $height ??= $value->value_number,
                default => null,
            };
        }

        $width = self::millimetres($width);
        $height = self::millimetres($height);

        if ($width === null || $height === null) {
            return null;
        }

        return self::make($width, $height, self::caption($product), (int) $product->id);
    }

    /**
     * Same diagram in another viewBox variant (unknown variant means "card").
     */
    public function withVariant(string $variant): self
    {
        return self::make($this->width, $this->height, $this->caption, $this->productId, $variant);
    }

    /**
     * Geometry for a valid size in mm (see millimetres()): a vertical liner strip running off
     * the top and bottom of the drawing area, a full middle label with its dimensions, and as
     * many neighbouring labels as reach the edges (the outer ones are clipped by the view).
     */
    public static function make(float $width, float $height, ?string $caption = null, int $productId = 0, string $variant = self::DEFAULT_VARIANT): self
    {
        $variant = array_key_exists($variant, self::VARIANTS) ? $variant : self::DEFAULT_VARIANT;

        $scale = min(self::AREA_W / $width, self::MAX_LABEL_H / $height);
        $labelW = min(self::AREA_W, max(self::MIN_DRAWN, $width * $scale));
        $labelH = min(self::MAX_LABEL_H, max(self::MIN_DRAWN, $height * $scale));

        $linerW = $labelW + 2 * self::LINER_PAD;
        $totalW = $linerW + self::DIM_ZONE_W;
        $linerX = (self::BASE_SIZE - $totalW) / 2;
        $labelX = $linerX + self::LINER_PAD;
        $middleY = (self::DRAW_H - $labelH) / 2;
        $pitch = $labelH + self::GAP;

        $labels = [];
        for ($i = -(int) ceil(self::DRAW_H / $pitch); $i <= (int) ceil(self::DRAW_H / $pitch); $i++) {
            $y = $middleY + $i * $pitch;
            if ($y < self::DRAW_H && $y + $labelH > 0.0) {
                $labels[] = ['x' => round($labelX, 2), 'y' => round($y, 2)];
            }
        }

        $a = min(self::ARROW, $labelW / 3, $labelH / 3);
        $h = self::ARROW_HALF;
        $right = $labelX + $labelW;
        $bottom = $middleY + $labelH;
        $widthY = $bottom + self::WIDTH_DIM_OFFSET;
        $dimX = $linerX + $linerW + self::HEIGHT_DIM_OFFSET;

        $extension = sprintf(
            'M%1$s %2$sV%3$sM%4$s %2$sV%3$sM%5$s %6$sH%7$sM%5$s %8$sH%7$s',
            round($labelX, 2), round($bottom + self::EXT_GAP, 2), round($widthY + self::EXT_OVERSHOOT, 2), round($right, 2),
            round($right + self::EXT_GAP, 2), round($middleY, 2), round($dimX + self::EXT_OVERSHOOT, 2), round($bottom, 2),
        );
        $line = sprintf(
            'M%s %sH%sM%s %sV%s',
            round($labelX + $a, 2), round($widthY, 2), round($right - $a, 2),
            round($dimX, 2), round($middleY + $a, 2), round($bottom - $a, 2),
        );
        $arrows = sprintf(
            'M%1$s %2$sL%3$s %4$sL%3$s %5$sZM%6$s %2$sL%7$s %4$sL%7$s %5$sZM%8$s %9$sL%10$s %11$sL%12$s %11$sZM%8$s %13$sL%10$s %14$sL%12$s %14$sZ',
            round($labelX, 2), round($widthY, 2), round($labelX + $a, 2), round($widthY - $h, 2), round($widthY + $h, 2),
            round($right, 2), round($right - $a, 2),
            round($dimX, 2), round($middleY, 2), round($dimX - $h, 2), round($middleY + $a, 2), round($dimX + $h, 2),
            round($bottom, 2), round($bottom - $a, 2),
        );

        return new self(
            productId: $productId,
            width: $width,
            height: $height,
            size: self::format($width).' × '.self::format($height),
            caption: $caption,
            variant: $variant,
            viewSize: self::VARIANTS[$variant]['size'],
            labels: $labels,
            labelW: round($labelW, 2),
            labelH: round($labelH, 2),
            cornerRadius: round(min(self::MAX_CORNER, $labelW / 5, $labelH / 4), 2),
            linerX: round($linerX, 2),
            linerW: round($linerW, 2),
            middleY: round($middleY, 2),
            extensionPath: $extension,
            dimensionLinePath: $line,
            arrowPath: $arrows,
            widthTextX: round($labelX + $labelW / 2, 2),
            widthTextY: round($widthY - self::WIDTH_TEXT_LIFT, 2),
            heightTextX: round($dimX + self::HEIGHT_TEXT_OFFSET, 2),
            heightTextY: round($middleY + $labelH / 2, 2),
            sizeTextY: self::SIZE_TEXT_Y,
            captionY: self::CAPTION_Y,
        );
    }

    /**
     * A size in mm within MIN_MM..MAX_MM, or null for anything else
     * (null, arrays, text, exponent notation, NaN, INF, zero, negatives, too big).
     */
    public static function millimetres(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value) && preg_match('/^\d{1,6}(\.\d{1,6})?$/', trim($value)) === 1) {
            $number = (float) trim($value);
        } else {
            return null;
        }

        if (! is_finite($number) || $number < self::MIN_MM || $number > self::MAX_MM) {
            return null;
        }

        return round($number, 2);
    }

    /**
     * 57, 25.4 (dot, no trailing zeros).
     */
    public static function format(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * "500 pcs · core 40 mm", only for exactly one (active) roll type with sane numbers.
     */
    private static function caption(Product $product): ?string
    {
        if (! $product->relationLoaded('activeRollTypes') || $product->activeRollTypes->count() !== 1) {
            return null;
        }

        /** @var RollType $roll */
        $roll = $product->activeRollTypes->first();
        $labels = (int) $roll->labels_per_roll;
        $core = is_numeric($roll->core_diameter_mm) ? (float) $roll->core_diameter_mm : null;

        if ($labels < RollType::MIN_LABELS || $labels > RollType::MAX_LABELS || $core === null || ! is_finite($core) || $core < RollType::MIN_CORE || $core > RollType::MAX_CORE) {
            return null;
        }

        return __('catalog.label_diagram.caption', ['count' => $labels, 'core' => self::format(round($core, 2))]);
    }
}
