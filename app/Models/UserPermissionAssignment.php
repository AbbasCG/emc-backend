<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermissionAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'permission_id',
        'state',
        'scope_type',
        'scope_id',
        'source_type',
        'source_id',
        'granted_by',
        'expires_at',
        'reason',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}
