@extends('emails.base')
@section('title', "انضممت إلى مسار: {$pathTitle}")
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">انضممت إلى مسار تعليمي! 🚀</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  أهلاً <strong>{{ $studentName }}</strong>، تم تسجيلك في المسار التعليمي. ستحصل على مهارات شاملة عبر سلسلة من الدورات المنظّمة بعناية.
</p>

<div style="background:#F0FDF4;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #22C55E;">
  <p style="margin:0;color:#166534;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل المسار</p>
  <p style="margin:12px 0 0;color:#22334A;font-size:16px;font-weight:700;">{{ $pathTitle }}</p>
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">عدد الدورات: <strong>{{ $coursesCount }} دورة</strong></p>
  @if($estimatedDuration)
  <p style="margin:6px 0 0;color:#475569;font-size:14px;">المدة التقديرية: <strong>{{ $estimatedDuration }}</strong></p>
  @endif
</div>

@if($pathUrl)
<div style="text-align:center;margin:32px 0;">
  <a href="{{ $pathUrl }}"
     style="background:#22C55E;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    ابدأ المسار التعليمي ←
  </a>
</div>
@else
<div style="text-align:center;margin:32px 0;">
  <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    انتقل إلى لوحة التحكم ←
  </a>
</div>
@endif

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
