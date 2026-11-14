<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityType;
use App\Enums\JobApplicationCvStatus;
use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Careers\CvFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * "Download CV" (plan 31, CR-8; Admin with careers.cv, throttle 20/min):
 * the bytes are fetched from Telegram (getFile → downloadFile) at the moment
 * of the download and never stored. 404 unless the CV was forwarded, the
 * file_unique_id Telegram reports equals the stored one and the bytes start
 * with %PDF-; Telegram errors become a generic localized 404 / 502 (never
 * Telegram's text or URL). Content-Type from the allow-list only, attachment
 * with an ASCII fallback name, and headers that stop the browser from
 * rendering, caching or sharing it. Every download is logged as an
 * activity (user id only).
 */
class DownloadJobApplicationCvController extends Controller
{
    /**
     * Allowed CV types (cv_mime => Content-Type).
     */
    public const MIME_ALLOW_LIST = [CvFile::MIME => CvFile::MIME];

    /**
     * Download limit above cv_max_kb (Telegram may report a slightly different size).
     */
    public const SLACK_BYTES = 64 * 1024;

    public function __invoke(JobApplication $jobApplication, TelegramClient $client): Response
    {
        Gate::authorize('downloadCv', $jobApplication);

        $mime = self::MIME_ALLOW_LIST[(string) $jobApplication->cv_mime] ?? null;

        abort_if($jobApplication->cv_status !== JobApplicationCvStatus::Forwarded
            || ! filled($jobApplication->cv_file_id) || ! filled($jobApplication->cv_file_unique_id) || $mime === null, 404, __('careers.admin.download.unavailable'));

        try {
            $file = $client->getFile((string) $jobApplication->cv_file_id);

            abort_unless(hash_equals((string) $jobApplication->cv_file_unique_id, $file['file_unique_id']), 404, __('careers.admin.download.unavailable'));

            $bytes = $client->downloadFile($file['file_path'], (int) config('careers.cv_max_kb', 5120) * 1024 + self::SLACK_BYTES);
        } catch (TelegramException $exception) {
            Log::warning('Careers CV download failed', ['job_application' => $jobApplication->id, 'code' => $exception->code()]);

            abort(in_array($exception->status, [400, 404], true) ? 404 : 502, __('careers.admin.download.unavailable'));
        }

        abort_unless(str_starts_with($bytes, CvFile::MAGIC), 404, __('careers.admin.download.unavailable'));

        $jobApplication->activities()->create([
            'user_id' => auth()->id(),
            'type' => ActivityType::CvDownload,
            'created_at' => now(),
        ]);

        $fallback = 'CV-'.$jobApplication->id.'.pdf';
        $name = CvFile::sanitizeName((string) ($jobApplication->cv_original_name ?: $fallback));

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
