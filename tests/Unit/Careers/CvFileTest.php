<?php

use App\Support\Careers\CvFile;

require_once __DIR__.'/../../Helpers/Careers.php';

it('accepts a plain PDF by content', function () {
    $cv = CvFile::inspect(careersUpload());

    expect($cv)->not->toBeNull()
        ->and($cv->mime)->toBe('application/pdf')
        ->and($cv->bytes)->toStartWith('%PDF-')
        ->and($cv->originalName)->toBe('Ali Valiyev CV.pdf');
});

it('refuses content that is not a safe PDF', function (string $bytes) {
    expect(CvFile::inspect(careersUpload($bytes, 'cv.pdf')))->toBeNull();
})->with([
    'exe' => "MZ\x90\x00".str_repeat("\x00", 100),
    'zip / docx' => "PK\x03\x04".str_repeat("\x00", 100),
    'html' => '<html><script>alert(1)</script></html>',
    'magic not at offset 0' => ' '.careersPdf(),
    '/JavaScript' => careersPdf('<< /S /JavaScript >>'),
    '/JS' => careersPdf('<< /JS (x) >>'),
    '/Launch' => careersPdf('<< /S /Launch >>'),
    '/EmbeddedFile' => careersPdf('<< /Type /EmbeddedFile >>'),
    '/RichMedia' => careersPdf('<< /RichMedia 1 0 R >>'),
    '/XFA' => careersPdf('<< /XFA 3 0 R >>'),
    'empty' => '',
]);

it('refuses a file above the size limit', function () {
    config(['careers.cv_max_kb' => 1]);

    expect(CvFile::inspect(careersUpload(careersPdf(str_repeat('a', 2048)))))->toBeNull();
});

it('sanitises the original name and forces .pdf', function (string $name, string $expected) {
    expect(CvFile::sanitizeName($name))->toBe($expected);
})->with([
    ['../../etc/passwd', 'passwd.pdf'],
    ['C:\\Users\\ali\\CV.PDF', 'CV.pdf'],
    ["evil\u{202E}fdp.exe", 'evilfdp.exe.pdf'],
    ['a:b*c?"d<e>f|g.pdf', 'abcdefg.pdf'],
    ["line\nbreak\x00.pdf", 'linebreak.pdf'],
    ['', 'CV.pdf'],
    [str_repeat('я', 150).'.pdf', str_repeat('я', 96).'.pdf'],
]);

it('accepts hand-made, object-stream and incremental-update PDFs', function (string $bytes) {
    expect(CvFile::looksLikeSafePdf($bytes))->toBeTrue();
})->with([
    'hand-made' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\nstartxref\n9\n%%EOF\n\n",
    'object stream' => fn () => careersPdf('5 0 obj << /Type /ObjStm /N 3 /First 12 /Length 40 >> stream endstream endobj'),
    'xref stream' => "%PDF-1.5\n1 0 obj\n<< /Type /XRef /Size 2 >>\nendobj\nstartxref\n9\n%%EOF",
    'incremental update' => "%PDF-1.4\n1 0 obj\n<< >>\nendobj\nstartxref\n9\n%%EOF\n2 0 obj\n<< >>\nendobj\nstartxref\n80\n%%EOF\n",
]);

it('rejects files with a PDF header but no PDF structure', function (string $bytes) {
    expect(CvFile::looksLikeSafePdf($bytes))->toBeFalse();
})->with([
    'header only' => "%PDF-1.4\n",
    'MZ body' => "%PDF-1.4\nMZ\x90\x00\x03\x00".str_repeat("\x00", 200)."This program cannot be run in DOS mode.\r\n",
    'MZ + PE with structure' => "%PDF-1.4\nMZ\x90".str_repeat("\x00", 60)."PE\0\0\n1 0 obj\n<< >>\nendobj\nstartxref\n0\n%%EOF\n",
    'ELF' => "%PDF-1.4\n\x7fELF\x02\x01\x01\n1 0 obj\n<< >>\nendobj\nstartxref\n0\n%%EOF\n",
    'random bytes' => "%PDF-1.4\n".str_repeat("\xA7\x13\x5C\xFE", 600),
]);
