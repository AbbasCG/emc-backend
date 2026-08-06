<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'status'];

    protected $hidden = ['secret'];

    protected $casts = ['events' => 'array'];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id')->latest();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function listensTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true)
            || in_array('*', $this->events ?? [], true);
    }
}
