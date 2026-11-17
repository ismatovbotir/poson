<?php

namespace App\Actions\Chat\Web;

use App\Models\WebChat;

/**
 * What the visitor's cookie points to (ResolveWebChat): a usable chat, a
 * banned one (refused; the cookie is kept, WC-14), or nothing.
 */
final readonly class ResolvedWebChat
{
    public function __construct(
        public ?WebChat $chat = null,
        public bool $banned = false,
    ) {}
}
