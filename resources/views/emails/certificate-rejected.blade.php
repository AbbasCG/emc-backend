@extends('emails.base')
@section('title', "بشأن طلب شهادتك")
@section('content')
<h2 style="color:#C53030;margin-top:0;font-size:22px;font-weight:800;">إشعار بشأن طلب شهادتك</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  عزيزي <strong>{{ $studentName }}</strong>، نود إعلامك بأن طلب شهادتك التالية لم يتمكن الفريق من اعتمادها في الوقت الحالي.
</p>

<div style="background:#FFF5F5;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #E53E3E;">
  <p style="margin:0;color:#C53030;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الشهادة</p>
  <p style="margin:12px 0 0;color:#22334A;font-size:16px;font-weight:700;">{{ $entityTitle }}</p>
  @if($certificateCode)
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">
    رقم الشهادة: <strong style="font-family:monospace;color:#C53030;">{{ $certificateCode }}</strong>
  </p>
  @endif
  @if($rejectionReason)
  <div style="margin:12px 0 0;background:#FED7D7;border-radius:8px;padding:10px 14px;">
    <p style="margin:0;color:#C53030;font-size:12px;font-weight:700;">سبب عدم الاعتماد</p>
    <p style="margin:6px 0 0;color:#742A2A;font-size:14px;line-height:1.7;">{{ $rejectionReason }}</p>
  </div>
  @endif
</div>

<p style="color:#475569;line-height:1.8;font-size:14px;">
  إذا كنت تعتقد أن هذا قرار غير صحيح أو لديك أسئلة، يرجى التواصل مع فريق الدعم.
</p>

<div style="text-align:center;margin:32px 0;">
  <a href="mailto:support@edumc.nl"
     style="background:#718096;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    التواصل مع الدعم ←
  </a>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC</p>
@endsection
