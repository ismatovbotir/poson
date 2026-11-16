<?php

namespace Database\Factories;

use App\Enums\AiKnowledgeSource;
use App\Models\AiKnowledgeChunk;
use App\Support\Search;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiKnowledgeChunk>
 */
class AiKnowledgeChunkFactory extends Factory
{
    /**
     * Define the model's default state: a page chunk in English.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->words(3, true);
        $text = fake()->sentence(12);

        return [
            'source_type' => AiKnowledgeSource::Page,
            'source_id' => fake()->unique()->numberBetween(1, 999_999),
            'locale' => 'en',
            'chunk' => 0,
            'title' => $title,
            'url' => 'https://poson.test/en/page/'.str_replace(' ', '-', $title),
            'text' => $text,
            'search_text' => Search::normalize($title.' '.$text),
            'priority' => AiKnowledgeSource::Page->priority(),
            'visible_from' => null,
            'content_hash' => hash('sha256', $title.$text),
            'indexed_at' => now(),
        ];
    }
}
