<?php

namespace App\Support\Qr;

use App\Services\Payments\Uzqr\UzqrQrText;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;
use Throwable;

/**
 * The only wrapper of bacon/bacon-qr-code (security conditions 46, 56):
 * fixed options (error correction M, size, margin), SVG and PNG writers
 * only, and only for a validated payload (printable ASCII ≤ 2000). Output
 * as data URIs for <img src> / a download link — never raw SVG in the page.
 *
 * The PNG ("save image") needs ext-gd; without it (or on any renderer
 * failure) pngDataUri() returns null and the page hides the button — the
 * SVG QR is still shown (change review M1).
 */
class QrRenderer
{
    public const SIZE = 320;

    public const MARGIN = 2;

    public function svgDataUri(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(self::SIZE, self::MARGIN), new SvgImageBackEnd));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($this->checked($text), 'ISO-8859-1', ErrorCorrectionLevel::M()));
    }

    public function pngDataUri(string $text): ?string
    {
        $text = $this->checked($text);

        if (! extension_loaded('gd')) {
            return null;
        }

        try {
            return 'data:image/png;base64,'.base64_encode($this->pngWriter()->writeString($text, 'ISO-8859-1', ErrorCorrectionLevel::M()));
        } catch (Throwable) {
            return null;
        }
    }

    protected function pngWriter(): Writer
    {
        return new Writer(new GDLibRenderer(self::SIZE * 2, self::MARGIN * 2));
    }

    private function checked(string $text): string
    {
        if (! UzqrQrText::isValid($text)) {
            throw new InvalidArgumentException('Refusing to render an invalid QR payload.');
        }

        return $text;
    }
}
