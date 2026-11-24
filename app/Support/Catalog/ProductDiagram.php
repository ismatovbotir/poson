<?php

namespace App\Support\Catalog;

use App\Models\Product;
use InvalidArgumentException;

/**
 * The generated fallback image of a product without an uploaded image (plan 38):
 * the label diagram for label products, else the roll diagram for ribbons and
 * receipt paper, else null (the placeholder symbol).
 *
 * toArray()/fromArray() carry the kind for the home cache; an unknown kind
 * throws, so the home page forgets the payload and rebuilds it (RD-7).
 */
final class ProductDiagram
{
    public const KINDS = ['label', 'roll'];

    public static function for(Product $product): LabelDiagram|RollDiagram|null
    {
        return LabelDiagram::for($product) ?? RollDiagram::for($product);
    }

    /**
     * @return array{kind: string, data: array<string, mixed>}
     */
    public static function toArray(LabelDiagram|RollDiagram $diagram): array
    {
        return ['kind' => $diagram instanceof LabelDiagram ? 'label' : 'roll', 'data' => $diagram->toArray()];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException for an unknown kind or a malformed payload
     */
    public static function fromArray(array $data): LabelDiagram|RollDiagram|null
    {
        $payload = $data['data'] ?? null;

        if (! is_array($payload)) {
            throw new InvalidArgumentException('Malformed product diagram payload.');
        }

        return match ($data['kind'] ?? null) {
            'label' => LabelDiagram::fromArray($payload),
            'roll' => RollDiagram::fromArray($payload),
            default => throw new InvalidArgumentException('Unknown product diagram kind.'),
        };
    }
}
