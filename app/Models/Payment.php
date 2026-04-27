<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'registration_id',
        'amount',
        'payment_method',
        'receipt_url',
        'status',
        'confirmed_at',
    ];
}
