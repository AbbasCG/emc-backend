<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    use Auditable;
    protected $fillable = [
        'source_request_id', 'name', 'type', 'partnership_type', 'project_scope',
        'classification', 'field_of_work', 'contact_person', 'contact_position',
        'email', 'phone', 'website', 'country', 'city', 'assigned_to',
        'first_contact_date', 'last_contact_date', 'rejection_reason', 'attachment_url',
        'status', 'onboarding_status', 'activated_at', 'notes',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'first_contact_date' => 'date',
        'last_contact_date' => 'date',
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
