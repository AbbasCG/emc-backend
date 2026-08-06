@extends('emails.base')
@section('title', 'تم استلام رسالتك')
@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#0891B2,#06B6D4);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">✉️</span>
  </div>
  <h2 style="color:#164E63;margin:0;font-size:22px;font-weight:800;">شكراً {{ $visitorName }}!</h2>
  <p style="color:#64748B;font-size:14px;margin:8px 0 0;">وصلتنا رسالتك وسيتواصل معك فريقنا قريباً</p>
</div>

@if(!empty($ticketNumber))
<div style="background:#E0F2FE;border-radius:10px;padding:12px 20px;margin:0 0 16px;text-align:center;">
  <p style="margin:0 0 4px;color:#0369A1;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">رقم تذكرتك</p>
  <p style="margin:0;color:#0C4A6E;font-size:18px;font-weight:800;">{{ $ticketNumber }}</p>
  <p style="margin:4px 0 0;color:#64748B;font-size:12px;">احتفظ بهذا الرقم للمتابعة</p>
</div>
@endif

<div style="background:#ECFEFF;border-radius:12px;padding:20px 24px;margin:0 0 20px;border-right:4px solid #06B6D4;">
  <p style="margin:0 0 10px;color:#155E75;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">ملخص رسالتك</p>
  <p style="margin:0 0 8px;color:#64748B;font-size:13px;">
    <strong style="color:#0E7490;">الموضوع:</strong> {{ $subject }}
  </p>
  <p style="margin:0 0 8px;color:#64748B;font-size:13px;">
    <strong style="color:#0E7490;">تاريخ الإرسال:</strong> {{ $submittedAt }}
  </p>
  <div style="background:#ffffff;border-radius:8px;padding:14px;margin-top:10px;border:1px solid #CFFAFE;">
    <p style="margin:0;color:#334155;font-size:14px;line-height:1.7;">{{ $messageBody }}</p>
  </div>
</div>

<div style="background:#F0FDFA;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
  <p style="margin:0 0 4px;color:#134E4A;font-size:13px;font-weight:700;">⏱️ وقت الاستجابة المتوقع</p>
  <p style="margin:0;color:#64748B;font-size:13px;line-height:1.6;">
    يعمل فريقنا على الرد خلال <strong style="color:#0E7490;">24–48 ساعة</strong> في أيام العمل.
    في حالات الاستفسارات العاجلة يمكنك التواصل معنا مباشرة على:
    <a href="mailto:{{ $supportEmail }}" style="color:#0891B2;font-weight:600;text-decoration:none;">{{ $supportEmail }}</a>
  </p>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
