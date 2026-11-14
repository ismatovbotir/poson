<?php

namespace App\Support\Careers;

use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * An inspected CV upload (plan 31, CR-4; PDF only, owner decision §17.5).
 * inspect() reads the file by content, never by its name or the browser's
 * type: "%PDF-" at offset 0, finfo application/pdf, and none of the active
 * content markers (/Launch, /JavaScript, /JS, /EmbeddedFile, /RichMedia,
 * /XFA) anywhere in the raw bytes. The original name is kept only for the
 * admin screen: basename, control / format characters and / \ : * ? " < > |
 * stripped, at most NAME_MAX characters, ".pdf" forced.
 */
final readonly class CvFile
{
    public const MIME = 'application/pdf';

    public const MAGIC = '%PDF-';

    public const NAME_MAX = 100;

    /**
     * Raw-byte markers of active PDF content (matched as PDF names: the
     * marker followed by a delimiter, so /JS does not match /JSON-like text
     * inside a longer name such as /JSomething).
     */
    public const BLOCKED_MARKERS = ['/Launch', '/JavaScript', '/JS', '/EmbeddedFile', '/EmbeddedFiles', '/RichMedia', '/XFA'];

    private function __construct(
        public string $bytes,
        public string $originalName,
        public string $mime,
        public int $size,
    ) {}

    /**
     * The inspected file, or null when it is not an acceptable PDF.
     */
    public static function inspect(UploadedFile $file): ?self
    {
        if (! $file->isValid()) {
            return null;
        }

        $maxBytes = (int) config('careers.cv_max_kb', 5120) * 1024;

        try {
            $size = (int) $file->getSize();

            if ($size <= 0 || $size > $maxBytes) {
                return null;
            }

            $bytes = (string) file_get_contents($file->getRealPath(), false, null, 0, $maxBytes + 1);
        } catch (Throwable) {
            return null;
        }

        if (strlen($bytes) > $maxBytes || ! self::looksLikeSafePdf($bytes)) {
            return null;
        }

        return new self($bytes, self::sanitizeName($file->getClientOriginalName()), self::MIME, strlen($bytes));
    }

    /**
     * Magic bytes, finfo, the PDF structure and the active-content markers.
     */
    public static function looksLikeSafePdf(string $bytes): bool
    {
        if (! str_starts_with($bytes, self::MAGIC)) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        if ($finfo->buffer($bytes) !== self::MIME) {
            return false;
        }

        if (! self::hasPdfStructure($bytes)) {
            return false;
        }

        // PDF name tokens may hide letters as #xx ("/J#61vaScript"): unescape before matching.
        $decoded = preg_replace_callback(
            '#/[^\s/<>\[\]()%{}]+#',
            fn (array $match): string => (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $hex): string => chr((int) hexdec($hex[1])), $match[0]),
            $bytes,
        );

        // Fail closed: a regex error (null / false) rejects the file.
        if (! is_string($decoded)) {
            return false;
        }

        foreach (self::BLOCKED_MARKERS as $marker) {
            if (preg_match('#'.preg_quote($marker, '#').'(?![A-Za-z0-9])#', $decoded) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Minimal PDF structure: no executable signature right after the header,
     * at least one obj ... endobj pair, a cross-reference (startxref or an
     * /XRef stream) and a %%EOF marker in the last 2 KB (several %%EOF from
     * incremental updates are fine).
     */
    private static function hasPdfStructure(string $bytes): bool
    {
        $head = substr($bytes, strlen(self::MAGIC), 4096);

        if (str_contains($head, "\x7fELF") || (str_contains($head, 'MZ') && str_contains($head, "PE\0\0"))) {
            return false;
        }

        if (preg_match('/\d+\s+\d+\s+obj\b/', $bytes) !== 1 || ! str_contains($bytes, 'endobj')) {
            return false;
        }

        if (! str_contains($bytes, 'startxref') && preg_match('#/Type\s*/XRef\b#', $bytes) !== 1) {
            return false;
        }

        return str_contains(substr($bytes, -2048), '%%EOF');
    }

    public static function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}\/\\\\:*?"<>|]/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .');
        $name = (string) preg_replace('/\.pdf$/i', '', $name);

        if ($name === '') {
            $name = 'CV';
        }

        return mb_substr($name, 0, self::NAME_MAX - 4).'.pdf';
    }
}
