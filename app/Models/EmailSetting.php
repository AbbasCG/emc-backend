<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailSetting extends Model
{
    protected $fillable = ['key', 'enabled', 'default_enabled', 'paused', 'sender_name', 'sender_email'];

    protected $casts = ['enabled' => 'boolean', 'default_enabled' => 'boolean', 'paused' => 'boolean'];

    protected static function booted(): void
    {
        // The setting is cached for read-heavy notification dispatch. Keep the
        // cache coherent when an administrator changes or removes a toggle.
        static::saved(fn (self $setting) => static::clearCache($setting->key));
        static::deleted(fn (self $setting) => static::clearCache($setting->key));
    }

    /**
     * May this email leave the platform at all (the old single admin switch)? True unless the type is paused for everyone.
     * Kept for the callers that only need that question; the full decision (personal choice, central default) is
     * App\Services\EmailDeliveryPolicy.
     */
    public static function isEnabled(string $key): bool
    {
        return ! static::isPaused($key);
    }

    /**
     * «إيقاف الإرسال للجميع مؤقتًا». A row the old code switched off (enabled = false) counts as paused too, so a type switched off
     * by the previous application, an old admin page during a rollout, or a rolled-back deployment still stops.
     *
     * These switches are operational controls; a five-minute stale value can send an email an administrator has just paused. The
     * table is tiny, so correctness is preferable to caching this single-row read.
     */
    public static function isPaused(string $key): bool
    {
        $setting = static::where('key', $key)->first();

        return $setting !== null && ($setting->paused || ! $setting->enabled);
    }

    /** «التفعيل افتراضيًا»: the state of everyone who follows the central setting. No row = on. */
    public static function defaultEnabled(string $key): bool
    {
        $setting = static::where('key', $key)->first();

        return $setting === null || (bool) $setting->default_enabled;
    }

    /**
     * Both central controls, written together. `enabled` mirrors NOT paused so the previous application (and a rollback) reads
     * the same stop.
     */
    public static function saveControls(string $key, ?bool $defaultEnabled = null, ?bool $paused = null): self
    {
        $row = static::firstOrNew(['key' => $key]);
        // A row the old code switched off (enabled = 0) IS paused, whatever its `paused` column says: read it that way first, so an
        // edit of the default alone never resumes it through the `enabled = NOT paused` mirror below.
        if ($row->exists && ! $row->enabled) {
            $row->paused = true;
        }
        if ($defaultEnabled !== null) {
            $row->default_enabled = $defaultEnabled;
        } elseif (! $row->exists) {
            $row->default_enabled = true;
        }
        if ($paused !== null) {
            $row->paused = $paused;
        } elseif (! $row->exists) {
            $row->paused = false;
        }
        $row->enabled = ! $row->paused;
        $row->save();

        return $row;
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
            'welcome', 'account_created', 'temporary_password', 'password_reset', 'email_verification',
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
            // Applications (Ambassador)
            'ambassador_confirmation', 'ambassador_admin_notification', 'ambassador_status_changed',
            // Oral Assessment
            'oral_assessment_booking_confirmed', 'oral_assessment_meeting_link_updated',
            'oral_assessment_instructor_message',
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
