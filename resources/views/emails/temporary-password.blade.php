@extends('emails.base')
@section('title', 'كلمة المرور المؤقتة')
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">مرحباً {{ $user->name }} 👋</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  تم إنشاء حسابك على منصة <strong style="color:#2691C2;">{{ config('app.name') }}</strong>. استخدم كلمة المرور المؤقتة أدناه لتسجيل الدخول لأول مرة.
</p>

<div style="background:#F8FAFC;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #2691C2;">
  <p style="margin:0;color:#64748B;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">بيانات الدخول</p>
  <p style="margin:12px 0 4px;color:#22334A;font-size:14px;">البريد الإلكتروني: <strong>{{ $user->email }}</strong></p>
  <p style="margin:8px 0 0;color:#64748B;font-size:13px;">كلمة المرور المؤقتة:</p>
  <div style="background:#EEF2FF;border-radius:8px;padding:14px 20px;margin-top:8px;text-align:center;">
    <span style="font-family:monospace;font-size:22px;font-weight:700;letter-spacing:3px;color:#3730A3;">{{ $temporaryPassword }}</span>
  </div>
</div>

<p style="color:#DC2626;font-size:13px;background:#FEF2F2;padding:12px 16px;border-radius:8px;border-right:3px solid #DC2626;line-height:1.7;">
  ⚠️ هذه كلمة مرور مؤقتة. يُرجى تسجيل الدخول وتغييرها فوراً من إعدادات حسابك. لا تشاركها مع أي شخص.
</p>

<div style="text-align:center;margin:32px 0;">
  <a href="{{ config('app.frontend_url', config('app.url')) }}/login"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    تسجيل الدخول ←
  </a>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
