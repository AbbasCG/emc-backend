@extends('emails.base')
@section('title', "فشل إنشاء ملف الشهادة")
@section('content')
<h2 style="color:#C53030;margin-top:0;font-size:22px;font-weight:800;">تنبيه: فشل إنشاء شهادة</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  مرحباً <strong>{{ $adminName }}</strong>، لم يتمكن النظام من إنشاء ملف الشهادة التالية بعد {{ $attempts }} محاولات.
</p>

<div style="background:#FFF5F5;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #E53E3E;">
  <p style="margin:0;color:#C53030;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الشهادة الفاشلة</p>
  <p style="margin:12px 0 4px;color:#22334A;font-size:16px;font-weight:700;">{{ $certificateTitle }}</p>
  <p style="margin:0;color:#475569;font-size:14px;">المستفيد: <strong>{{ $studentName }}</strong></p>
  @if($certificateCode)
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">رمز الشهادة: <strong style="font-family:monospace;color:#C53030;">{{ $certificateCode }}</strong></p>
  @endif
  @if($errorMessage)
  <div style="margin:12px 0 0;background:#FED7D7;border-radius:8px;padding:10px 14px;">
    <p style="margin:0;color:#C53030;font-size:12px;font-weight:700;">رسالة الخطأ</p>
    <p style="margin:4px 0 0;color:#742A2A;font-size:12px;font-family:monospace;word-break:break-all;">{{ $errorMessage }}</p>
  </div>
  @endif
</div>

<div style="text-align:center;margin:32px 0;">
  <a href="{{ $adminUrl }}"
     style="background:#E53E3E;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    عرض الشهادة وإعادة المحاولة ←
  </a>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">نظام EMC — تنبيه تلقائي</p>
@endsection
