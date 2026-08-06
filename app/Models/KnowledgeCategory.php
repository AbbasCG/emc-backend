<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeCategory extends Model
{
    protected $fillable = [
        'parent_id', 'department_id', 'name', 'slug', 'description', 'sort_order', 'status',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeArticle::class, 'category_id');
    }
}
