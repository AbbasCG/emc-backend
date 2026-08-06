@extends('emails.base')
@section('title', "تم تقييم واجبك: {$assignmentTitle}")
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">تم تقييم واجبك ✅</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  أهلاً <strong>{{ $studentName }}</strong>، تم مراجعة وتقييم واجبك من قبل المدرب.
</p>

<div style="background:#F0FDF4;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #22C55E;">
  <p style="margin:0;color:#166534;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">نتيجة التقييم</p>
  <p style="margin:12px 0 0;color:#22334A;font-size:15px;">الدورة: <strong>{{ $courseTitle }}</strong></p>
  <p style="margin:8px 0 0;color:#22334A;font-size:15px;">الواجب: <strong>{{ $assignmentTitle }}</strong></p>
  @if($score !== null)
  <p style="margin:12px 0 0;color:#22334A;font-size:22px;font-weight:800;">
    الدرجة:
    <span style="color:#22C55E;">{{ $score }}</span>
    @if($maxScore !== null)<span style="color:#94A3B8;font-size:16px;"> / {{ $maxScore }}</span>@endif
  </p>
  @endif
</div>

@if($feedback)
<div style="background:#F8FAFC;border-radius:12px;padding:20px 24px;margin:20px 0;border-right:4px solid #94A3B8;">
  <p style="margin:0;color:#64748B;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">ملاحظات المدرب</p>
  <p style="margin:10px 0 0;color:#475569;font-size:14px;line-height:1.7;">{{ $feedback }}</p>
</div>
@endif

@if($dashboardUrl)
<div style="text-align:center;margin:32px 0;">
  <a href="{{ $dashboardUrl }}"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    عرض التقييم كاملاً ←
  </a>
</div>
@else
<div style="text-align:center;margin:32px 0;">
  <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard/student/assignments"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    عرض واجباتي ←
  </a>
</div>
@endif

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
