<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    // ── Action metadata (label in Arabic, Tailwind color, Heroicon key) ─────────
    public const ACTION_META = [
        'CREATE'     => ['label' => 'إنشاء',         'color' => 'blue',    'icon' => 'plus'],
        'UPDATE'     => ['label' => 'تعديل',          'color' => 'amber',   'icon' => 'edit'],
        'DELETE'     => ['label' => 'حذف',            'color' => 'red',     'icon' => 'trash'],
        'LOGIN'      => ['label' => 'تسجيل دخول',    'color' => 'green',   'icon' => 'login'],
        'LOGOUT'     => ['label' => 'تسجيل خروج',    'color' => 'gray',    'icon' => 'logout'],
        'ENROLL'     => ['label' => 'تسجيل',          'color' => 'cyan',    'icon' => 'user-plus'],
        'APPROVE'    => ['label' => 'موافقة',          'color' => 'green',   'icon' => 'check'],
        'REJECT'     => ['label' => 'رفض',            'color' => 'red',     'icon' => 'x'],
        'PUBLISH'    => ['label' => 'نشر',             'color' => 'emerald', 'icon' => 'eye'],
        'UNPUBLISH'  => ['label' => 'إلغاء النشر',    'color' => 'orange',  'icon' => 'eye-off'],
        'EXPORT'     => ['label' => 'تصدير',           'color' => 'purple',  'icon' => 'download-cloud'],
        'IMPORT'     => ['label' => 'استيراد',         'color' => 'indigo',  'icon' => 'upload-cloud'],
        'DOWNLOAD'   => ['label' => 'تنزيل',           'color' => 'sky',     'icon' => 'download'],
        // Legacy lowercase actions (backward compat)
        'created'        => ['label' => 'إنشاء',         'color' => 'blue',    'icon' => 'plus'],
        'updated'        => ['label' => 'تعديل',          'color' => 'amber',   'icon' => 'edit'],
        'deleted'        => ['label' => 'حذف',            'color' => 'red',     'icon' => 'trash'],
        'restored'       => ['label' => 'استعادة',        'color' => 'teal',    'icon' => 'refresh'],
        'status_changed' => ['label' => 'تغيير الحالة',  'color' => 'amber',   'icon' => 'switch-horizontal'],
        'role_changed'   => ['label' => 'تغيير الدور',   'color' => 'purple',  'icon' => 'badge-check'],
        'approved'       => ['label' => 'موافقة',          'color' => 'green',   'icon' => 'check'],
        'rejected'       => ['label' => 'رفض',            'color' => 'red',     'icon' => 'x'],
        'final_approved' => ['label' => 'موافقة نهائية',  'color' => 'emerald', 'icon' => 'check-circle'],
        'student_enrolled'   => ['label' => 'تسجيل طالب', 'color' => 'cyan', 'icon' => 'user-plus'],
        'student_unenrolled' => ['label' => 'إلغاء تسجيل', 'color' => 'orange', 'icon' => 'user-minus'],
    ];

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'user_role',
        'action',
        'entity_type',
        'entity_id',
        'entity_name',
        'description',
        'old_values',
        'new_values',
        'changed_fields',
        'metadata',
        'ip_address',
        'user_agent',
        'route',
        'method',
    ];

    protected $casts = [
        'old_values'     => 'array',
        'new_values'     => 'array',
        'changed_fields' => 'array',
        'metadata'       => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getActionMetaAttribute(): array
    {
        return self::ACTION_META[$this->action] ?? [
            'label' => $this->action,
            'color' => 'gray',
            'icon'  => 'circle',
        ];
    }
}
