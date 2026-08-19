<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionNode extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function parent()
    {
        return $this->belongsTo(PermissionNode::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(PermissionNode::class, 'parent_id')->orderBy('sort_order');
    }
}
