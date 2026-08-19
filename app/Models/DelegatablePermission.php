<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DelegatablePermission extends Model
{
    protected $guarded = [];

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
