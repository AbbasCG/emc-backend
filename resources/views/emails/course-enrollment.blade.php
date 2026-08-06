@extends('emails.base')
@section('title', "تم تسجيلك في الدورة: {$courseTitle}")
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">تم تسجيلك بنجاح! 🎉</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  أهلاً <strong>{{ $studentName }}</strong>، تم تسجيلك في الدورة التدريبية بنجاح. نتمنى لك رحلة تعلم ممتعة ومثمرة.
</p>

<div style="background:#F0F9FF;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #2691C2;">
  <p style="margin:0;color:#0369A1;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الدورة</p>
  <p style="margin:12px 0 0;color:#22334A;font-size:16px;font-weight:700;">{{ $courseTitle }}</p>
  @if($instructorName)
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">المدرب: <strong>{{ $instructorName }}</strong></p>
  @endif
  @if($startDate)
  <p style="margin:6px 0 0;color:#475569;font-size:14px;">تاريخ البدء: <strong>{{ $startDate }}</strong></p>
  @endif
  @if($duration)
  <p style="margin:6px 0 0;color:#475569;font-size:14px;">المدة: <strong>{{ $duration }}</strong></p>
  @endif
</div>

@if($courseUrl)
<div style="text-align:center;margin:32px 0;">
  <a href="{{ $courseUrl }}"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    ابدأ الدراسة الآن ←
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
