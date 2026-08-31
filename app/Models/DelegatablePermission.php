<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelegatablePermission extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'scope_type' => 'department',
        'can_grant'  => true,
        'can_revoke' => true,
    ];

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
