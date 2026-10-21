<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An external datasheet link by default; file() for an uploaded file record.
 *
 * @extends Factory<ProductDocument>
 */
class ProductDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'title' => ['uz' => 'Texnik maʼlumot', 'ru' => 'Техническое описание', 'en' => 'Datasheet'],
            'type' => DocumentType::Datasheet,
            'locale' => null,
            'path' => null,
            'url' => 'https://example.com/docs/'.fake()->uuid().'.pdf',
            'original_name' => null,
            'size' => null,
            'sort' => 0,
        ];
    }

    /**
     * A stored file (the file itself is not created).
     */
    public function file(string $extension = 'pdf'): static
    {
        return $this->state(fn (array $attributes) => [
            'path' => 'product-documents/'.fake()->uuid().'.'.$extension,
            'url' => null,
            'original_name' => 'document.'.$extension,
            'size' => 1024,
        ]);
    }
}
