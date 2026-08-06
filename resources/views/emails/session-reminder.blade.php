@extends('emails.base')
@section('title', "تذكير بالجلسة: {$sessionTitle}")
@section('content')
@php $label = $hoursAhead >= 20 ? 'غداً' : 'بعد ساعة واحدة'; @endphp
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">تذكير بجلستك القادمة ⏰</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  أهلاً <strong>{{ $studentName }}</strong>، لا تنسَ جلستك التدريبية. موعدها <strong style="color:#EC943C;">{{ $label }}</strong>!
</p>

<div style="background:#FFF7ED;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #EC943C;">
  <p style="margin:0;color:#92400E;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الجلسة</p>
  <p style="margin:12px 0 0;color:#22334A;font-size:16px;font-weight:700;">{{ $sessionTitle }}</p>
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">الدورة: <strong>{{ $courseTitle }}</strong></p>
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">الموعد: <strong>{{ $startsAt }}</strong></p>
</div>

@if($meetingLink)
<div style="text-align:center;margin:32px 0;">
  <a href="{{ $meetingLink }}"
     style="background:#EC943C;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    انضم إلى الجلسة ←
  </a>
</div>
@else
<div style="text-align:center;margin:32px 0;">
  <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard/student/sessions"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    عرض جلساتي ←
  </a>
</div>
@endif

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
