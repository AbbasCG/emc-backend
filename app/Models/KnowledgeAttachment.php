<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeAttachment extends Model
{
    protected $fillable = ['article_id', 'file_path', 'file_name', 'file_type', 'file_size'];

    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'article_id');
    }
}
