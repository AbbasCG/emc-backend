@extends('emails.base')
@section('title', 'مرحباً بك في منصة EMC')
@section('content')

{{-- Icon + heading --}}
<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#1D4ED8,#3B82F6);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">👤</span>
  </div>
  <h2 style="color:#1E3A5F;margin:0;font-size:22px;font-weight:800;">أهلاً {{ $userName }}!</h2>
  <p style="color:#64748B;font-size:14px;margin:8px 0 0;">تم إنشاء حسابك في منصة <strong style="color:#1D4ED8;">EMC</strong> بنجاح</p>
</div>

{{-- Account details card --}}
<div style="background:#EFF6FF;border-radius:12px;padding:20px 24px;margin:0 0 20px;border-right:4px solid #3B82F6;">
  <p style="margin:0 0 4px;color:#1E40AF;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">بيانات حسابك</p>
  <table style="width:100%;border-collapse:collapse;margin-top:12px;">
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;width:40%;">الاسم الكامل</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:4px 0;">{{ $userName }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;">البريد الإلكتروني</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:4px 0;">{{ $userEmail }}</td>
    </tr>
    @if($temporaryPassword)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;">كلمة المرور المؤقتة</td>
      <td style="padding:4px 0;">
        <span style="background:#DBEAFE;color:#1D4ED8;padding:4px 12px;border-radius:6px;font-family:monospace;font-size:15px;font-weight:700;letter-spacing:1px;">{{ $temporaryPassword }}</span>
      </td>
    </tr>
    @endif
  </table>
</div>

@if($temporaryPassword)
<div style="background:#FEF2F2;border-radius:10px;padding:14px 18px;margin-bottom:24px;border-right:3px solid #EF4444;">
  <p style="margin:0;color:#DC2626;font-size:13px;font-weight:600;">⚠️ تنبيه أمني</p>
  <p style="margin:6px 0 0;color:#7F1D1D;font-size:13px;line-height:1.6;">
    كلمة المرور المؤقتة صالحة للاستخدام مرة واحدة فقط. يُرجى تغييرها فور تسجيل دخولك لأول مرة لضمان أمان حسابك.
  </p>
</div>
@endif

<div style="text-align:center;margin:28px 0;">
  <a href="{{ $loginUrl }}"
     style="background:linear-gradient(135deg,#1D4ED8,#3B82F6);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    تسجيل الدخول ←
  </a>
</div>

<div style="background:#F8FAFC;border-radius:10px;padding:16px 20px;margin-top:8px;text-align:center;">
  <p style="margin:0;color:#64748B;font-size:13px;line-height:1.7;">
    تحتاج مساعدة؟ تواصل معنا على
    <a href="mailto:support@edumc.nl" style="color:#1D4ED8;text-decoration:none;font-weight:600;">support@edumc.nl</a>
  </p>
</div>
@endsection
