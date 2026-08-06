@extends('emails.base')
@section('title', "شهادتك جاهزة للتحميل")
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">شهادتك جاهزة! 🏅</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  تهانينا يا <strong>{{ $studentName }}</strong>! تم إصدار شهادتك وأصبحت جاهزة للتحميل.
</p>

<div style="background:#FFFBEB;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #EC943C;">
  <p style="margin:0;color:#92400E;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الشهادة</p>
  <p style="margin:12px 0 4px;color:#22334A;font-size:16px;font-weight:700;">{{ $entityTitle }}</p>
  @if($certificateCode)
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">
    رقم الشهادة:
    <strong style="font-family:monospace;color:#92400E;">{{ $certificateCode }}</strong>
  </p>
  @endif
  @if($issuedAt)
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">تاريخ الإصدار: <strong>{{ $issuedAt }}</strong></p>
  @endif
  @if($verificationCode)
  <p style="margin:10px 0 0;color:#475569;font-size:14px;">
    رمز التحقق:
    <strong style="background:#FEF3C7;padding:3px 10px;border-radius:4px;font-family:monospace;color:#92400E;font-size:15px;">{{ $verificationCode }}</strong>
  </p>
  @endif
</div>

<div style="text-align:center;margin:32px 0;">
  <a href="{{ $downloadUrl }}"
     style="background:#EC943C;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    تحميل الشهادة ←
  </a>
</div>

<p style="color:#64748B;font-size:13px;line-height:1.7;text-align:center;">
  يمكنك مشاركة الشهادة أو التحقق منها باستخدام رمز التحقق أعلاه.
</p>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
