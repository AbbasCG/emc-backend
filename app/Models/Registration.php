<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'user_id',
        'track_id',
        'workshop_id',
        'full_name',
        'phone',
        'email',
        'city',
        'gender',
        'notes',
        'status',

    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function track()
    {
        return $this->belongsTo(Track::class);
    }

    public function workshop()
    {
        return $this->belongsTo(Workshop::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
