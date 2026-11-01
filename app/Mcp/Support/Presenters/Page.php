<?php

namespace App\Mcp\Support\Presenters;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Pager of MCP list tools: at most MAX_PER_PAGE rows per page, page <= MAX_PAGE,
 * and a has_more flag (one extra row is read to know it).
 */
final class Page
{
    public const MAX_PER_PAGE = 50;

    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PAGE = 1000;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'between:1,'.self::MAX_PAGE],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  validated arguments
     */
    public static function make(array $input): self
    {
        $page = is_numeric($input['page'] ?? null) ? (int) $input['page'] : 1;
        $perPage = is_numeric($input['per_page'] ?? null) ? (int) $input['per_page'] : self::DEFAULT_PER_PAGE;

        return new self(max(1, min(self::MAX_PAGE, $page)), max(1, min(self::MAX_PER_PAGE, $perPage)));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Runs the query for this page and maps each row through $present
     * (an explicit field allow-list, never toArray()).
     *
     * @template TRow
     *
     * @param  EloquentBuilder<*>|Builder  $query
     * @param  callable(mixed): TRow  $present
     * @return array{items: list<TRow>, page: int, per_page: int, has_more: bool}
     */
    public function fetch(EloquentBuilder|Builder $query, callable $present): array
    {
        $rows = $query->offset($this->offset())->limit($this->perPage + 1)->get();

        return [
            'items' => $rows->take($this->perPage)->map($present)->values()->all(),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'has_more' => $rows->count() > $this->perPage,
        ];
    }
}
