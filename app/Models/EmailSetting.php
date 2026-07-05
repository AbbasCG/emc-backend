<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailSetting extends Model
{
    protected $fillable = ['key', 'enabled', 'sender_name', 'sender_email'];

    protected $casts = ['enabled' => 'boolean'];

    public static function isEnabled(string $key): bool
    {
        return Cache::remember("email_setting_{$key}", 300, function () use ($key) {
            $setting = static::where('key', $key)->first();
            return $setting === null || (bool) $setting->enabled;
        });
    }

    public static function clearCache(string $key): void
    {
        Cache::forget("email_setting_{$key}");
    }

    public static function clearAllCache(): void
    {
        // Forget known keys — cache tags are not available on all drivers.
        // EmailSettingsController::LABELS lists all known keys.
        $keys = [
            // Account
            'welcome', 'account_created', 'temporary_password', 'password_reset',
            // Learning
            'course_enrollment', 'learning_path_enrollment',
            'course_completion', 'learning_path_completion',
            'assignment_submitted', 'assignment_graded', 'session_reminder',
            // Admin
            'admin_new_registration',
            // Payment
            'payment_confirmed',
            // Contact / Support
            'contact_confirmation', 'contact_admin_notification', 'support_ticket_reply',
            // Volunteer
            'volunteer_confirmation', 'volunteer_admin_notification',
            // Workshop
            'workshop_request_confirmation', 'workshop_request_submitted',
            'workshop_request_admin_notification',
            // Certificate
            'certificate_ready', 'certificate_rejected', 'certificate_revoked',
            'certificate_needs_approval', 'certificate_pdf_failed',
        ];
        foreach ($keys as $key) {
            Cache::forget("email_setting_{$key}");
        }
        Log::debug('[EmailSetting] Cache cleared');
    }

    public static function senderName(): string
    {
        $row = static::where('key', 'global_sender_name')->first();
        $override = $row?->sender_name;
        return ($override && trim($override) !== '') ? $override : (string) config('mail.from.name', 'EMC Platform');
    }

    public static function senderEmail(): string
    {
        $row = static::where('key', 'global_sender_email')->first();
        $override = $row?->sender_email;
        return ($override && trim($override) !== '') ? $override : (string) config('mail.from.address', 'no-reply@emc.test');
    }
}
