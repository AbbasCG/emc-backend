@extends('emails.base')
@section('title', "تسليم واجب جديد: {$assignmentTitle}")
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">تسليم واجب جديد 📋</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  أهلاً <strong>{{ $instructorName }}</strong>، قام الطالب بتسليم واجبه وينتظر مراجعتك وتقييمك.
</p>

<div style="background:#EFF6FF;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #2691C2;">
  <p style="margin:0;color:#1D4ED8;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل التسليم</p>
  <p style="margin:12px 0 0;color:#22334A;font-size:15px;">الطالب: <strong>{{ $studentName }}</strong></p>
  <p style="margin:8px 0 0;color:#22334A;font-size:15px;">الدورة: <strong>{{ $courseTitle }}</strong></p>
  <p style="margin:8px 0 0;color:#22334A;font-size:15px;">الواجب: <strong>{{ $assignmentTitle }}</strong></p>
</div>

@if($reviewUrl)
<div style="text-align:center;margin:32px 0;">
  <a href="{{ $reviewUrl }}"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    مراجعة الواجب ←
  </a>
</div>
@else
<div style="text-align:center;margin:32px 0;">
  <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard/instructor/submissions"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    الذهاب إلى التسليمات ←
  </a>
</div>
@endif

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
