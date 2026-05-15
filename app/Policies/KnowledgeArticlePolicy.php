<?php

namespace App\Policies;

use App\Models\KnowledgeArticle;
use App\Models\User;

class KnowledgeArticlePolicy
{
    public function viewAny(?User $user): bool
    {
        return true; // visibility enforced in query scope
    }

    public function view(?User $user, KnowledgeArticle $article): bool
    {
        if ($article->visibility === 'public') {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $article->visibility === 'internal';
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission(\App\Support\Permissions::MANAGE_KNOWLEDGE);
    }

    public function update(User $user, KnowledgeArticle $article): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission(\App\Support\Permissions::MANAGE_KNOWLEDGE)
            && $article->author_id === $user->id;
    }

    public function delete(User $user, KnowledgeArticle $article): bool
    {
        return $user->isAdmin();
    }
}
