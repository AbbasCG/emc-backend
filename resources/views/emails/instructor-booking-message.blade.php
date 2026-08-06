@extends('emails.base')
@section('title', 'رسالة بخصوص موعد المقابلة الشفهية')
@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#2691C2,#22334A);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">🎙️</span>
  </div>
  <h2 style="color:#164E63;margin:0;font-size:22px;font-weight:800;">رسالة من المدرب</h2>
</div>

<p style="color:#334155;font-size:15px;line-height:1.8;margin:0 0 20px;">
  السلام عليكم {{ $studentName }}،
</p>
<p style="color:#475569;font-size:14px;line-height:1.8;margin:0 0 24px;">
  وصلتك رسالة من المدرب {{ $instructorName }} بخصوص موعد مقابلتك الشفهية لدورة "{{ $courseTitle }}":
</p>

<div style="background:#ECFEFF;border-radius:12px;padding:16px 20px;margin:0 0 20px;border-right:4px solid #2691C2;">
  <p style="margin:0 0 4px;color:#155E75;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">رقم الموعد المرجعي</p>
  <p style="margin:0;color:#0E7490;font-size:15px;font-weight:800;">{{ $bookingReference }}</p>
</div>

<div style="background:#F8FAFC;border-radius:12px;padding:20px 24px;margin:0 0 20px;border:1px solid #E2E8F0;">
  <p style="margin:0;color:#334155;font-size:14px;line-height:1.9;white-space:pre-wrap;">{{ $body }}</p>
</div>

@if($instructorEmail)
<div style="background:#F0FDFA;border-radius:10px;padding:16px 20px;margin-bottom:24px;border:1px solid #CCFBF1;">
  <p style="margin:0;color:#64748B;font-size:13px;line-height:1.6;">
    يمكنك الرد مباشرة على هذه الرسالة، وستصل إلى المدرب على:
    <a href="mailto:{{ $instructorEmail }}" style="color:#2691C2;font-weight:600;text-decoration:none;">{{ $instructorEmail }}</a>
  </p>
</div>
@endif

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
