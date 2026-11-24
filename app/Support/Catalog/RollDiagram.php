<?php

namespace App\Support\Catalog;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\HexColor;

/**
 * Generated roll diagram (plan 38): the fallback image of a ribbon or a receipt
 * paper roll without an uploaded image, the sibling of LabelDiagram (same
 * contract: for(), make(), withVariant(), closed VARIANTS, toArray()/fromArray()).
 *
 * A horizontal roll seen from its left end: the end face with the wound layers and
 * the core (or a solid spiral when coreless), the body, a strip running off the
 * bottom with its width dimension, the length on the body, and for ribbons the
 * core diameter leader (Ø), the ink band (outer face for OUT, next to the core
 * for IN) and a type tag.
 *
 * Every input is parsed strictly (anchored regex, ranges, allow-lists, closed keys;
 * RD-12) and re-validated in make(), also from the cache (RD-7). No database text
 * reaches the SVG: labels come from lang (catalog.roll_diagram.*), numbers are
 * range-checked, the colour is a normalized "#RRGGBB" used only as a fill
 * attribute, classes come from closed maps (RD-8).
 *
 * Needs "media" and "rollSpecValues.attribute" + "rollSpecValues.option" (or
 * "attributeValues.attribute" + "attributeValues.option") loaded; returns null
 * rather than lazy loading.
 *
 * Cached by HomeCache (plan 34): when toArray()/fromArray() change shape, raise
 * HomeCache::SCHEMA.
 */
final readonly class RollDiagram
{
    public const KIND_RIBBON = 'ribbon';

    public const KIND_RECEIPT = 'receipt';

    public const RIBBON_WIDTH = 'ribbon_width';

    public const RIBBON_LENGTH = 'ribbon_length';

    public const RIBBON_TYPE = 'ribbon_type';

    public const INK_SIDE = 'ink_side';

    public const CORE_DIAMETER = 'core_diameter';

    public const RIBBON_COLOUR = 'ribbon_colour';

    public const PAPER_WIDTH = 'paper_width';

    public const PAPER_LENGTH = 'paper_length';

    public const ROLL_CORE = 'roll_core';

    /**
     * Spec codes the diagram reads (Product::rollSpecValues()).
     *
     * @var list<string>
     */
    public const CODES = [
        self::RIBBON_WIDTH, self::RIBBON_LENGTH, self::RIBBON_TYPE, self::INK_SIDE, self::CORE_DIAMETER, self::RIBBON_COLOUR,
        self::PAPER_WIDTH, self::PAPER_LENGTH, self::ROLL_CORE,
    ];

    /**
     * Width (mm) and length (m) ranges per kind.
     *
     * @var array<string, array{width: array{0: float, 1: float}, length: array{0: float, 1: float}}>
     */
    public const RANGES = [
        self::KIND_RIBBON => ['width' => [10.0, 250.0], 'length' => [10.0, 2000.0]],
        self::KIND_RECEIPT => ['width' => [20.0, 120.0], 'length' => [5.0, 500.0]],
    ];

    /**
     * Core diameters (mm) a ribbon may show, with the drawn core radius as a share of the roll radius.
     *
     * @var array<string, float>
     */
    public const CORES = ['12.7' => 0.2, '25' => 0.28, '25.4' => 0.28, '40' => 0.36, '76' => 0.5];

    /** @var list<string> */
    public const INK_SIDES = ['in', 'out'];

    /** @var list<string> */
    public const RIBBON_TYPES = ['wax', 'wax_resin', 'resin'];

    public const DEFAULT_HEX = '#111111';

    /**
     * Closed server-side map of viewBox variants, as in LabelDiagram.
     *
     * @var array<string, array{size: int, unit: float}>
     */
    public const VARIANTS = LabelDiagram::VARIANTS;

    public const DEFAULT_VARIANT = 'card';

    public const BASE_SIZE = 200.0;

    public const DRAW_H = 140.0;

    /**
     * Outline / hatch / text classes on a light or a dark body (luminance > HexColor::LIGHT_LUMINANCE).
     *
     * @var array<string, array{outline: string, hatch: string, text: string}>
     */
    public const TONES = [
        'light' => ['outline' => 'stroke-ink-2', 'hatch' => 'stroke-ink-3', 'text' => 'fill-ink'],
        'dark' => ['outline' => 'stroke-ink-line', 'hatch' => 'stroke-white', 'text' => 'fill-white'],
    ];

    /** Neutral fills (paper, film back, layers, core, hole) from a closed list. */
    public const NEUTRAL_FILLS = ['paper' => 'fill-white', 'film' => 'fill-ink-soft', 'core' => 'fill-label-liner-edge', 'hole' => 'fill-bg-soft'];

    private const ROLL_TOP = 18.0;

    private const ASPECT = 0.36;

    private const SKEW = 8.0;

    private const STRIP_BOTTOM = 118.0;

    private const DIM_Y = 133.0;

    private const ARROW = 4.0;

    private const ARROW_HALF = 1.5;

    private const LEADER_ZONE = 36.0;

    private const SIZE_TEXT_Y = self::DRAW_H + 23.0;

    private const CAPTION_Y = self::DRAW_H + 43.0;

    /**
     * All geometry is in the 200-unit base design (the view scales it by unit()).
     */
    private function __construct(
        public int $productId,
        public string $kind,
        public float $width,
        public float $length,
        public ?float $core,
        public ?string $inkSide,
        public ?string $ribbonType,
        public ?string $hex,
        public bool $coreless,
        public string $variant,
        public int $viewSize,
        public string $title,
        public string $size,
        public ?string $caption,
        public ?string $tag,
        public ?string $coreText,
        public string $widthText,
        public string $lengthText,
        public ?string $bodyHex,
        public string $bodyClass,
        public ?string $bandHex,
        public string $bandPath,
        public string $outlineClass,
        public string $hatchClass,
        public string $textClass,
        public float $faceX,
        public float $faceY,
        public float $faceRx,
        public float $faceRy,
        public string $faceClass,
        public string $bodyPath,
        public string $stripPath,
        public string $hatchPath,
        public string $ringsPath,
        public string $stripLinesPath,
        public ?float $coreRx,
        public ?float $coreRy,
        public ?float $holeRx,
        public ?float $holeRy,
        public string $spiralPath,
        public string $leaderPath,
        public float $coreTextX,
        public float $coreTextY,
        public string $extensionPath,
        public string $dimensionLinePath,
        public string $arrowPath,
        public float $widthTextX,
        public float $widthTextY,
        public float $lengthTextX,
        public float $lengthTextY,
        public float $tagX,
        public float $tagY,
        public float $tagW,
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
        if ($product->isPricedPerLabel() || ! $product->relationLoaded('media') || $product->media->isNotEmpty()) {
            return null;
        }

        $values = match (true) {
            $product->relationLoaded('rollSpecValues') => $product->rollSpecValues,
            $product->relationLoaded('attributeValues') => $product->attributeValues,
            default => null,
        };

        if ($values === null) {
            return null;
        }

        $specs = [];

        /** @var ProductAttributeValue $value */
        foreach ($values as $value) {
            if (! $value->relationLoaded('attribute')) {
                continue;
            }

            $code = $value->attribute?->code;

            if ($code === null || ! in_array($code, self::CODES, true) || array_key_exists($code, $specs)) {
                continue;
            }

            $option = $value->relationLoaded('option') ? $value->option : null;

            $specs[$code] = [
                'number' => $value->value_number,
                'uz' => $option?->getTranslation('value', 'uz', false),
                'en' => $option?->getTranslation('value', 'en', false),
                'hex' => $option?->hexColor(),
            ];
        }

        if ($product->type !== ProductType::Consumable) {
            return null;
        }

        if (isset($specs[self::RIBBON_WIDTH], $specs[self::RIBBON_LENGTH])) {
            return self::make(
                self::KIND_RIBBON,
                $specs[self::RIBBON_WIDTH]['number'],
                $specs[self::RIBBON_LENGTH]['number'],
                core: isset($specs[self::CORE_DIAMETER]) ? self::core($specs[self::CORE_DIAMETER]['uz']) : null,
                inkSide: self::inkSide($specs[self::INK_SIDE]['uz'] ?? null),
                ribbonType: self::ribbonType($specs[self::RIBBON_TYPE] ?? null),
                hex: $specs[self::RIBBON_COLOUR]['hex'] ?? null,
                productId: (int) $product->id,
            );
        }

        if (isset($specs[self::PAPER_WIDTH], $specs[self::PAPER_LENGTH])) {
            return self::make(
                self::KIND_RECEIPT,
                $specs[self::PAPER_WIDTH]['uz'],
                $specs[self::PAPER_LENGTH]['number'],
                coreless: self::rollCore($specs[self::ROLL_CORE] ?? null) === 'coreless',
                productId: (int) $product->id,
            );
        }

        return null;
    }

    /**
     * Same diagram in another viewBox variant (unknown variant means "card").
     */
    public function withVariant(string $variant): self
    {
        return self::build($this->kind, $this->width, $this->length, $this->core, $this->inkSide, $this->ribbonType, $this->hex, $this->coreless, $this->productId, $variant);
    }

    /**
     * The diagram for raw inputs, every one re-validated (also from the cache): null
     * for an unknown kind or a width / length outside its range; an invalid core,
     * ink side or type is dropped, an invalid colour means DEFAULT_HEX.
     */
    public static function make(
        mixed $kind,
        mixed $width,
        mixed $length,
        mixed $core = null,
        mixed $inkSide = null,
        mixed $ribbonType = null,
        mixed $hex = null,
        mixed $coreless = false,
        int $productId = 0,
        string $variant = self::DEFAULT_VARIANT,
    ): ?self {
        if (! is_string($kind) || ! isset(self::RANGES[$kind])) {
            return null;
        }

        $range = self::RANGES[$kind];
        $width = self::number($width, ...$range['width']);
        $length = self::number($length, ...$range['length']);

        if ($width === null || $length === null) {
            return null;
        }

        $ribbon = $kind === self::KIND_RIBBON;
        $core = $ribbon ? self::core($core) : null;

        return self::build(
            $kind,
            $width,
            $length,
            $core,
            $ribbon && in_array($inkSide, self::INK_SIDES, true) ? $inkSide : null,
            $ribbon && in_array($ribbonType, self::RIBBON_TYPES, true) ? $ribbonType : null,
            $ribbon ? (HexColor::normalize($hex) ?? self::DEFAULT_HEX) : null,
            ! $ribbon && $coreless === true,
            $productId,
            $variant,
        );
    }

    /**
     * A number within min..max (rounded to 2 decimals), or null for anything else: strings
     * only as plain digits with an optional fraction ("60", "60.0000"; anchored, so no
     * trailing newline, sign, exponent or blank), finite ints / floats.
     */
    public static function number(mixed $value, float $min, float $max): ?float
    {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value) && preg_match('/\A\d{1,6}(\.\d{1,6})?\z/', $value) === 1) {
            $number = (float) $value;
        } else {
            return null;
        }

        if (! is_finite($number) || $number < $min || $number > $max) {
            return null;
        }

        return round($number, 2);
    }

    /**
     * A core diameter from the allow-list (12.7, 25, 25.4, 40, 76 mm), else null.
     */
    public static function core(mixed $value): ?float
    {
        $number = self::number($value, 1.0, 100.0);

        return $number !== null && isset(self::CORES[LabelDiagram::format($number)]) ? $number : null;
    }

    /**
     * "in" / "out" for an option value of exactly "IN" / "OUT", else null.
     */
    public static function inkSide(mixed $value): ?string
    {
        return match ($value) {
            'IN' => 'in',
            'OUT' => 'out',
            default => null,
        };
    }

    /**
     * Closed ribbon type key from the option's en value, else its uz value (anchored at the
     * start, wax-resin checked before wax): wax | wax_resin | resin | null.
     *
     * @param  array{uz?: mixed, en?: mixed}|null  $option
     */
    public static function ribbonType(?array $option): ?string
    {
        foreach ([$option['en'] ?? null, $option['uz'] ?? null] as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            return match (true) {
                preg_match('/\Awax[\s\-\/]*resin(?![a-z])/i', $value) === 1 => 'wax_resin',
                preg_match('/\Awax(?![a-z\-\/])/i', $value) === 1 => 'wax',
                preg_match('/\Aresin(?![a-z])/i', $value) === 1 => 'resin',
                default => null,
            };
        }

        return null;
    }

    /**
     * Closed core key of the roll_core option (en, else uz; exact): with_core | coreless | null.
     *
     * @param  array{uz?: mixed, en?: mixed}|null  $option
     */
    public static function rollCore(?array $option): ?string
    {
        foreach ([$option['en'] ?? null, $option['uz'] ?? null] as $value) {
            if (! is_string($value)) {
                continue;
            }

            if (preg_match('/\A(coreless|gilzasiz)\z/i', $value) === 1) {
                return 'coreless';
            }

            if (preg_match('/\A(with core|gilzali)\z/i', $value) === 1) {
                return 'with_core';
            }
        }

        return null;
    }

    /**
     * Geometry and texts for already validated inputs.
     */
    private static function build(string $kind, float $width, float $length, ?float $core, ?string $inkSide, ?string $ribbonType, ?string $hex, bool $coreless, int $productId, string $variant): self
    {
        $variant = array_key_exists($variant, self::VARIANTS) ? $variant : self::DEFAULT_VARIANT;
        $ribbon = $kind === self::KIND_RIBBON;

        // Roll radius from the length, drawn length of the roll from the width (both clamped).
        $r = $ribbon ? 30.0 + min(1.0, $length / 600.0) * 12.0 : 20.0 + min(1.0, $length / 100.0) * 20.0;
        $rx = $r * self::ASPECT;
        $bodyLen = $ribbon ? min(112.0, max(34.0, 26.0 + $width * 0.6)) : min(104.0, max(34.0, 20.0 + $width * 0.8));

        $left = $ribbon && $core !== null ? self::LEADER_ZONE : 8.0;
        $available = self::BASE_SIZE - $left - 8.0;
        $needed = 2 * $rx + $bodyLen + self::SKEW;
        $xL = $left + $rx + max(0.0, ($available - $needed) / 2);
        $xR = $xL + $bodyLen;
        $cy = self::ROLL_TOP + $r;
        $top = $cy - $r;
        $bottom = $cy + $r;

        // Fills: the ribbon body shows the ink colour unless the ink faces inwards (film back).
        $bodyHex = $ribbon && $inkSide !== 'in' ? $hex : null;
        $bodyClass = $ribbon ? self::NEUTRAL_FILLS['film'] : self::NEUTRAL_FILLS['paper'];
        $tone = self::TONES[$bodyHex !== null && ! HexColor::isLight($bodyHex) ? 'dark' : 'light'];

        $coreShare = $ribbon ? (self::CORES[$core !== null ? LabelDiagram::format($core) : '25']) : 0.3;
        $coreR = $coreless ? null : $r * $coreShare;
        $holeR = $coreR !== null ? $coreR * 0.72 : null;
        $innerR = $coreR ?? $r * 0.12;

        // Wound layers on the end face.
        $rings = '';
        foreach ([0.25, 0.5, 0.75] as $share) {
            $rings .= self::ellipse($xL, $cy, ($innerR + ($r - $innerR) * $share) * self::ASPECT, $innerR + ($r - $innerR) * $share);
        }

        // Ink band: outer face (OUT) at the rim, inner face (IN) next to the core.
        $band = '';
        $bandHex = null;
        if ($ribbon && $inkSide !== null) {
            [$outer, $inner] = $inkSide === 'out' ? [$r, $r - ($r - $innerR) * 0.16] : [$innerR + ($r - $innerR) * 0.16, $innerR];
            $band = self::ellipse($xL, $cy, $outer * self::ASPECT, $outer).self::ellipse($xL, $cy, $inner * self::ASPECT, $inner);
            $bandHex = $hex;
        }

        $spiral = '';
        if ($coreless) {
            $step = $r * 0.07;
            $radius = $r * 0.04;
            $spiral = sprintf('M%s %s', self::n($xL + $radius * self::ASPECT), self::n($cy));
            for ($i = 1; $i <= 7; $i++) {
                $radius += $step;
                $spiral .= sprintf('A%s %s 0 0 1 %s %s', self::n($radius * self::ASPECT), self::n($radius), self::n($xL + ($i % 2 === 1 ? -1 : 1) * $radius * self::ASPECT), self::n($cy));
            }
        }

        $body = sprintf('M%1$s %2$sH%3$sA%4$s %5$s 0 0 1 %3$s %6$sH%1$sZ', self::n($xL), self::n($top), self::n($xR), self::n($rx), self::n($r), self::n($bottom));
        $strip = sprintf('M%s %sH%sL%s %sH%sZ', self::n($xL), self::n($bottom), self::n($xR), self::n($xR + self::SKEW), self::n(self::STRIP_BOTTOM), self::n($xL + self::SKEW));
        $hatch = sprintf('M%1$s %2$sH%3$sM%1$s %4$sH%3$s', self::n($xL + 3), self::n($cy - $r * 0.55), self::n($xR), self::n($cy + $r * 0.62));

        $stripLines = '';
        $stripH = self::STRIP_BOTTOM - $bottom;
        if (! $ribbon && $stripH >= 14.0) {
            foreach ([0.3, 0.55, 0.8] as $j => $share) {
                $y = $bottom + $stripH * $share;
                $shift = self::SKEW * $share;
                $stripLines .= sprintf('M%s %sH%s', self::n($xL + $shift + 6), self::n($y), self::n($xR + $shift - 6 - $j * 10));
            }
        }

        // Width dimension under the strip.
        $x1 = $xL + self::SKEW;
        $x2 = $xR + self::SKEW;
        $a = self::ARROW;
        $h = self::ARROW_HALF;
        $extension = sprintf('M%1$s %2$sV%3$sM%4$s %2$sV%3$s', self::n($x1), self::n(self::STRIP_BOTTOM + 1.5), self::n(self::DIM_Y + 2), self::n($x2));
        $line = sprintf('M%s %sH%s', self::n($x1 + $a), self::n(self::DIM_Y), self::n($x2 - $a));
        $arrows = sprintf(
            'M%1$s %2$sL%3$s %4$sL%3$s %5$sZM%6$s %2$sL%7$s %4$sL%7$s %5$sZ',
            self::n($x1), self::n(self::DIM_Y), self::n($x1 + $a), self::n(self::DIM_Y - $h), self::n(self::DIM_Y + $h), self::n($x2), self::n($x2 - $a),
        );

        // Core diameter leader (ribbons, allow-listed core only).
        $leader = '';
        $coreText = null;
        if ($ribbon && $core !== null) {
            $leader = sprintf('M%s %sL%s %sH%s', self::n($xL), self::n($cy), self::n($xL - $rx - 4), self::n($top - 3), self::n(4.0));
            $coreText = __('catalog.roll_diagram.core', ['core' => LabelDiagram::format($core)]);
        }

        $mm = __('catalog.roll_diagram.mm');
        $m = __('catalog.roll_diagram.m');
        $size = __('catalog.roll_diagram.size', ['width' => LabelDiagram::format($width), 'length' => LabelDiagram::format($length)]);
        $tag = $ribbon && $ribbonType !== null ? __('catalog.roll_diagram.type.'.$ribbonType) : null;
        $caption = implode(' · ', array_filter($ribbon
            ? [$tag, $inkSide !== null ? __('catalog.roll_diagram.ink.'.$inkSide) : null]
            : [__('catalog.roll_diagram.thermal'), $coreless ? __('catalog.roll_diagram.coreless') : null]));
        $tagW = $tag !== null ? round(mb_strlen($tag) * 5.0 + 10.0, 2) : 0.0;

        return new self(
            productId: $productId,
            kind: $kind,
            width: $width,
            length: $length,
            core: $core,
            inkSide: $inkSide,
            ribbonType: $ribbonType,
            hex: $hex,
            coreless: $coreless,
            variant: $variant,
            viewSize: self::VARIANTS[$variant]['size'],
            title: __('catalog.roll_diagram.title_'.$kind, ['size' => $size]),
            size: $size,
            caption: $caption !== '' ? $caption : null,
            tag: $tag,
            coreText: $coreText,
            widthText: LabelDiagram::format($width).' '.$mm,
            lengthText: LabelDiagram::format($length).' '.$m,
            bodyHex: $bodyHex,
            bodyClass: $bodyClass,
            bandHex: $bandHex,
            bandPath: $band,
            outlineClass: $tone['outline'],
            hatchClass: $tone['hatch'],
            textClass: $tone['text'],
            faceX: self::n($xL),
            faceY: self::n($cy),
            faceRx: self::n($rx),
            faceRy: self::n($r),
            faceClass: $ribbon ? self::NEUTRAL_FILLS['film'] : self::NEUTRAL_FILLS['paper'],
            bodyPath: $body,
            stripPath: $strip,
            hatchPath: $hatch,
            ringsPath: $rings,
            stripLinesPath: $stripLines,
            coreRx: $coreR !== null ? self::n($coreR * self::ASPECT) : null,
            coreRy: $coreR !== null ? self::n($coreR) : null,
            holeRx: $holeR !== null ? self::n($holeR * self::ASPECT) : null,
            holeRy: $holeR !== null ? self::n($holeR) : null,
            spiralPath: $spiral,
            leaderPath: $leader,
            coreTextX: 4.0,
            coreTextY: self::n($top - 5),
            extensionPath: $extension,
            dimensionLinePath: $line,
            arrowPath: $arrows,
            widthTextX: self::n(($x1 + $x2) / 2),
            widthTextY: self::n(self::DIM_Y - 3),
            lengthTextX: self::n(($xL + $xR) / 2 + $rx),
            lengthTextY: self::n($cy + 3),
            tagX: self::n(self::BASE_SIZE - 4 - $tagW),
            tagY: 2.0,
            tagW: $tagW,
            sizeTextY: self::SIZE_TEXT_Y,
            captionY: self::CAPTION_Y,
        );
    }

    /**
     * A closed ellipse as a path (two half arcs).
     */
    private static function ellipse(float $cx, float $cy, float $rx, float $ry): string
    {
        return sprintf('M%1$s %2$sA%3$s %4$s 0 1 0 %5$s %2$sA%3$s %4$s 0 1 0 %1$s %2$sZ', self::n($cx - $rx), self::n($cy), self::n($rx), self::n($ry), self::n($cx + $rx));
    }

    private static function n(float $value): float
    {
        return round($value, 2);
    }

    /**
     * The inputs of make() as a plain array for the home cache (HC-2); the geometry and texts are recomputed.
     *
     * @return array{kind: string, width: float, length: float, core: float|null, ink_side: string|null, ribbon_type: string|null, hex: string|null, coreless: bool, product_id: int, variant: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'width' => $this->width,
            'length' => $this->length,
            'core' => $this->core,
            'ink_side' => $this->inkSide,
            'ribbon_type' => $this->ribbonType,
            'hex' => $this->hex,
            'coreless' => $this->coreless,
            'product_id' => $this->productId,
            'variant' => $this->variant,
        ];
    }

    /**
     * Back from the cache through make(), so every value is validated again (RD-7):
     * null when the width or length is no longer valid.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        return self::make(
            $data['kind'] ?? null,
            $data['width'] ?? null,
            $data['length'] ?? null,
            $data['core'] ?? null,
            $data['ink_side'] ?? null,
            $data['ribbon_type'] ?? null,
            $data['hex'] ?? null,
            $data['coreless'] ?? false,
            is_int($data['product_id'] ?? null) ? $data['product_id'] : 0,
            is_string($data['variant'] ?? null) ? $data['variant'] : self::DEFAULT_VARIANT,
        );
    }
}
