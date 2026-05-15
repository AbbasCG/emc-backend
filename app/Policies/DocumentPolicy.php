<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(?User $user): bool
    {
        return true; // visibility enforced per record
    }

    public function view(?User $user, Document $document): bool
    {
        if ($document->visibility === 'public') {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($document->visibility === 'internal') {
            return true; // any authenticated user
        }

        if ($document->visibility === 'private') {
            return $document->uploaded_by === $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    public function update(User $user, Document $document): bool
    {
        return $user->isAdmin() || $document->uploaded_by === $user->id;
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->isAdmin() || $document->uploaded_by === $user->id;
    }
}
