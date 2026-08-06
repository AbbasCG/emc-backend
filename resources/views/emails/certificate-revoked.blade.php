@extends('emails.base')
@section('title', "إلغاء شهادتك")
@section('content')
<h2 style="color:#C53030;margin-top:0;font-size:22px;font-weight:800;">إشعار إلغاء شهادة</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  عزيزي <strong>{{ $studentName }}</strong>، نود إعلامك بأنه تم إلغاء شهادتك التالية.
</p>

<div style="background:#FFF5F5;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #E53E3E;">
  <p style="margin:0;color:#C53030;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">الشهادة الملغاة</p>
  <p style="margin:12px 0 0;color:#22334A;font-size:16px;font-weight:700;">{{ $certificateTitle }}</p>
  @if($certificateCode)
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">
    رمز الشهادة:
    <strong style="font-family:monospace;color:#C53030;">{{ $certificateCode }}</strong>
  </p>
  @endif
  @if($reason)
  <p style="margin:12px 0 0;color:#718096;font-size:13px;">سبب الإلغاء: <em>{{ $reason }}</em></p>
  @endif
</div>

<p style="color:#475569;line-height:1.8;font-size:14px;">
  إذا كنت تعتقد أن هذا خطأ، يرجى التواصل مع الفريق الإداري.
</p>

<div style="text-align:center;margin:32px 0;">
  <a href="{{ config('app.frontend_url', config('app.url')) }}/dashboard/certificates"
     style="background:#718096;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    عرض شهاداتي ←
  </a>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC</p>
@endsection
