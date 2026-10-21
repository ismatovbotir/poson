<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Models\Concerns\StoresTranslations;
use Database\Factories\ProductDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * Product document (plan 03 D11): a file on the private DISK (path) or an
 * external https link (url), never both.
 *
 * Files are private: staff download them through the authorized route
 * admin.products.documents.download. The public site (plan 3d) will need its
 * own download route for documents of published products.
 *
 * @property int $id
 * @property int $product_id
 * @property DocumentType $type
 * @property string|null $locale
 * @property string|null $path
 * @property string|null $url
 * @property string|null $original_name
 * @property int|null $size bytes
 * @property int $sort
 */
#[Fillable(['product_id', 'title', 'type', 'locale', 'path', 'url', 'original_name', 'size', 'sort'])]
class ProductDocument extends Model
{
    /** @use HasFactory<ProductDocumentFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    /**
     * Private disk of uploaded document files.
     */
    public const DISK = 'local';

    /**
     * Accepted file extensions (validated against the content type too).
     */
    public const EXTENSIONS = ['pdf', 'zip', 'exe'];

    /**
     * Largest upload in kilobytes (20 MB).
     */
    public const MAX_KILOBYTES = 20480;

    /**
     * @var list<string>
     */
    public array $translatable = ['title'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'size' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isFile(): bool
    {
        return $this->path !== null;
    }

    /**
     * File extension in upper case ("PDF") or "URL" for a link.
     */
    public function kind(): string
    {
        return $this->isFile() ? mb_strtoupper(pathinfo((string) $this->path, PATHINFO_EXTENSION)) : 'URL';
    }

    /**
     * Where staff open the document: the authorized download route or the link.
     */
    public function adminUrl(): string
    {
        return $this->isFile()
            ? route('admin.products.documents.download', $this)
            : (string) $this->url;
    }
}
