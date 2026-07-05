@extends('emails.base')
@section('title', 'رد على تذكرتك')
@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#0891B2,#06B6D4);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">💬</span>
  </div>
  <h2 style="color:#164E63;margin:0;font-size:22px;font-weight:800;">لديك رد جديد على تذكرتك</h2>
  <p style="color:#64748B;font-size:14px;margin:8px 0 0;">{{ $recipientName }}</p>
</div>

<div style="background:#ECFEFF;border-radius:12px;padding:16px 20px;margin:0 0 20px;border-right:4px solid #06B6D4;">
  <p style="margin:0 0 4px;color:#155E75;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">رقم التذكرة</p>
  <p style="margin:0;color:#0E7490;font-size:15px;font-weight:800;">{{ $ticketNumber }}</p>
  @if($ticketSubject)
  <p style="margin:8px 0 0;color:#64748B;font-size:13px;"><strong style="color:#0E7490;">الموضوع:</strong> {{ $ticketSubject }}</p>
  @endif
</div>

<div style="background:#F8FAFC;border-radius:12px;padding:20px 24px;margin:0 0 20px;border:1px solid #E2E8F0;">
  <p style="margin:0 0 12px;color:#475569;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">رد فريق الدعم</p>
  <p style="margin:0;color:#334155;font-size:14px;line-height:1.8;white-space:pre-wrap;">{{ $replyMessage }}</p>
</div>

<div style="background:#F0FDFA;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
  <p style="margin:0 0 4px;color:#134E4A;font-size:13px;font-weight:700;">📧 للتواصل أو الاستفسار</p>
  <p style="margin:0;color:#64748B;font-size:13px;line-height:1.6;">
    يمكنك الرد على هذا البريد مباشرة أو التواصل معنا على:
    <a href="mailto:{{ $supportEmail }}" style="color:#0891B2;font-weight:600;text-decoration:none;">{{ $supportEmail }}</a>
  </p>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">مع تحيات فريق EMC 💙</p>
@endsection
