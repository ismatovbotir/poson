<?php

namespace App\Actions\Mcp;

use App\Actions\Videos\AttachVideo;
use App\Mcp\Support\VideoOwner;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Attach a video as an INACTIVE draft for the MCP attach_video tool (plan 19,
 * YT-S13): AttachVideo with active=false passed explicitly, whoever the caller
 * is. Authorization comes before the duplicate and limit checks, and a missing,
 * trashed or forbidden owner gives the same error. No outbound HTTP here: the
 * title and preview are fetched later by the queued job.
 */
class CreateMcpVideoDraft
{
    public const OWNER_NOT_FOUND = 'Video owner not found.';

    public function __construct(private AttachVideo $attach) {}

    /**
     * @param  array{owner?: mixed, url?: mixed, title?: mixed, published_on?: mixed}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): Video
    {
        $owner = VideoOwner::model($data['owner'] ?? null);

        if ($owner === null || ! Gate::forUser($user)->allows('create', [Video::class, $owner])) {
            throw ValidationException::withMessages(['owner' => self::OWNER_NOT_FOUND]);
        }

        return $this->attach->handle($owner, $data['url'] ?? null, [
            'title' => $data['title'] ?? null,
            'published_on' => $data['published_on'] ?? null,
        ], active: false);
    }
}
