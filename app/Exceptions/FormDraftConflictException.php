<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The editor tried to save a draft that someone else changed after the editor loaded it (the revision moved on).
 * The controller turns it into a 409 that carries the current draft, so the builder can offer "load the latest" or an
 * explicit overwrite. Nothing is ever overwritten silently.
 */
class FormDraftConflictException extends RuntimeException
{
    public function __construct(public readonly int $currentRevision)
    {
        parent::__construct('تم تعديل مسودة النموذج من قبل شخص آخر بعد أن فتحتها.');
    }
}
