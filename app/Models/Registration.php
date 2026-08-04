<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Registration extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'course_id',
        'user_id',
        'track_id',
        'workshop_id',
        'learning_path_id',
        'full_name',
        'phone',
        'email',
        'city',
        'country',
        'country_code',
        'phone_country_code',
        'gender',
        'notes',
        'status',
        'levels_purchased',
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

    public function learningPath()
    {
        return $this->belongsTo(LearningPath::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** Match registrations owned by the authenticated student (user_id or email). */
    public function scopeForStudent(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere('email', $user->email);
        });
    }

    /** Statuses that grant LMS access — must stay aligned with StudentRegistrationsController::courses. */
    public function scopeActiveEnrollment(Builder $query): Builder
    {
        return $query->whereIn('status', RegistrationStatus::activeEnrollment());
    }
}
