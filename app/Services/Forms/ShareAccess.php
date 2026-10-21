<?php

namespace App\Services\Forms;

use App\Models\FormShareLink;

/**
 * What a request said about share links: nothing, a link that is valid, or a token that is not usable (and why).
 *
 * `$link` is set only for a usable link. `$problem` is one of the FormAvailabilityService share states. A token that was
 * presented but is unusable is a refusal of its own, never silently ignored, so a revoked or expired link stops working even
 * on a form that is otherwise open to everyone.
 */
final class ShareAccess
{
    public function __construct(
        public readonly bool $presented = false,
        public readonly ?FormShareLink $link = null,
        public readonly ?string $problem = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
