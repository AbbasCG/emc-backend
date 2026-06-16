@extends('emails.base')
@section('title', 'مرحباً بك في منصة EMC')
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">أهلاً {{ $userName }}! 👋</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  يسعدنا انضمامك إلى منصة <strong style="color:#2691C2;">EMC</strong> للتعلم الرقمي. حسابك جاهز الآن ويمكنك البدء في استكشاف الدورات التدريبية المتاحة.
</p>

<div style="background:#F8FAFC;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #2691C2;">
  <p style="margin:0;color:#64748B;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">بيانات حسابك</p>
  <p style="margin:10px 0 0;color:#22334A;font-size:14px;">البريد الإلكتروني: <strong>{{ $userEmail }}</strong></p>
  @if($tempPassword)
  <p style="margin:8px 0 0;color:#22334A;font-size:14px;">
    كلمة المرور المؤقتة:
    <strong style="background:#FEF3C7;padding:3px 10px;border-radius:4px;font-family:monospace;font-size:15px;color:#92400E;">{{ $tempPassword }}</strong>
  </p>
  @endif
</div>

@if($tempPassword)
<p style="color:#DC2626;font-size:13px;background:#FEF2F2;padding:12px 16px;border-radius:8px;border-right:3px solid #DC2626;">
  ⚠️ يُرجى تغيير كلمة المرور فور تسجيل دخولك الأول لحماية حسابك.
</p>
@endif

<div style="text-align:center;margin:32px 0;">
  <a href="{{ config('app.frontend_url', config('app.url')) }}/login"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    تسجيل الدخول ←
  </a>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
