@extends('emails.base')
@section('title', "شهادة تحتاج موافقتك")
@section('content')
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">طلب اعتماد شهادة</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  مرحباً <strong>{{ $approverName }}</strong>، لديك شهادة جديدة تنتظر موافقتك.
</p>

<div style="background:#FFFBEB;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid #EC943C;">
  <p style="margin:0;color:#92400E;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الطلب</p>
  <p style="margin:12px 0 4px;color:#22334A;font-size:16px;font-weight:700;">{{ $certificateTitle }}</p>
  <p style="margin:0;color:#475569;font-size:14px;">المستفيد: <strong>{{ $studentName }}</strong></p>
  @if($certificateCode)
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">رمز الشهادة: <strong style="font-family:monospace;">{{ $certificateCode }}</strong></p>
  @endif
  <p style="margin:8px 0 0;color:#475569;font-size:14px;">نوع الموافقة المطلوبة: <strong>{{ $approvalType }}</strong></p>
</div>

<div style="text-align:center;margin:32px 0;">
  <a href="{{ $approvalUrl }}"
     style="background:#EC943C;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    مراجعة الشهادة واعتمادها ←
  </a>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
