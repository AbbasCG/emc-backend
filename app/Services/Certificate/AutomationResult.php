<?php

namespace App\Services\Certificate;

use App\Models\Certificate;

final class AutomationResult
{
    private function __construct(
        public readonly string       $outcome,   // 'created' | 'duplicate' | 'skipped' | 'blocked'
        public readonly ?Certificate $certificate,
        public readonly bool         $isNew,
        public readonly ?string      $reason = null,
    ) {}

    public static function created(Certificate $cert): self
    {
        return new self('created', $cert, true);
    }

    public static function duplicate(Certificate $cert): self
    {
        return new self('duplicate', $cert, false);
    }

    public static function skipped(Certificate $cert): self
    {
        return new self('skipped', $cert, false);
    }

    /** No certificate row, PDF, or notification is ever created for a
     *  blocked result — the caller must return this BEFORE any persistence. */
    public static function blocked(string $reason): self
    {
        return new self('blocked', null, false, $reason);
    }

    public function wasDuplicate(): bool { return $this->outcome === 'duplicate'; }
    public function wasCreated(): bool   { return $this->outcome === 'created'; }
    public function wasBlocked(): bool   { return $this->outcome === 'blocked'; }
}
