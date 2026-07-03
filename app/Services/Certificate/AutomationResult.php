<?php

namespace App\Services\Certificate;

use App\Models\Certificate;

final class AutomationResult
{
    private function __construct(
        public readonly string       $outcome,   // 'created' | 'duplicate' | 'skipped'
        public readonly Certificate  $certificate,
        public readonly bool         $isNew,
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

    public function wasDuplicate(): bool { return $this->outcome === 'duplicate'; }
    public function wasCreated(): bool   { return $this->outcome === 'created'; }
}
