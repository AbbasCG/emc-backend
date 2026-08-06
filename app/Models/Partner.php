<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    use Auditable;
    protected $fillable = [
        'name', 'type', 'contact_person', 'email', 'phone',
        'website', 'country', 'city', 'status', 'notes',
    ];

    public function partnerUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PartnerUser::class);
    }

    public function programs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PartnerProgram::class);
    }

    public function reports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PartnerReport::class);
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PartnerDocument::class);
    }
}
