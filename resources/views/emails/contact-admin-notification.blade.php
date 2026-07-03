@extends('emails.base')
@section('title', 'رسالة جديدة من نموذج التواصل')
@section('content')

<div style="text-align:center;margin-bottom:24px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#D97706,#F59E0B);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">📩</span>
  </div>
  <h2 style="color:#78350F;margin:0;font-size:21px;font-weight:800;">رسالة جديدة من نموذج التواصل</h2>
  <p style="color:#64748B;font-size:13px;margin:6px 0 0;">{{ $submittedAt }}</p>
</div>

{{-- Visitor info --}}
<div style="background:#FFFBEB;border-radius:12px;padding:20px 24px;margin:0 0 16px;border-right:4px solid #F59E0B;">
  <p style="margin:0 0 12px;color:#92400E;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">بيانات المرسل</p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;width:35%;">الاسم</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:4px 0;">{{ $visitorName }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;">البريد الإلكتروني</td>
      <td style="padding:4px 0;">
        <a href="mailto:{{ $visitorEmail }}" style="color:#D97706;font-weight:600;text-decoration:none;">{{ $visitorEmail }}</a>
      </td>
    </tr>
    @if($visitorPhone)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;">الهاتف</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:4px 0;">{{ $visitorPhone }}</td>
    </tr>
    @endif
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;">الفئة</td>
      <td style="padding:4px 0;">
        <span style="background:#FDE68A;color:#92400E;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600;">{{ $category }}</span>
      </td>
    </tr>
  </table>
</div>

{{-- Message content --}}
<div style="background:#F8FAFC;border-radius:12px;padding:20px 24px;margin:0 0 20px;border:1px solid #E2E8F0;">
  <p style="margin:0 0 8px;color:#475569;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">الموضوع</p>
  <p style="margin:0 0 16px;color:#1E3A5F;font-size:15px;font-weight:700;">{{ $subject }}</p>
  <p style="margin:0 0 8px;color:#475569;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">نص الرسالة</p>
  <p style="margin:0;color:#334155;font-size:14px;line-height:1.8;white-space:pre-wrap;">{{ $messageBody }}</p>
</div>

<div style="text-align:center;margin:24px 0;">
  <a href="{{ $adminUrl }}"
     style="background:linear-gradient(135deg,#D97706,#F59E0B);color:#ffffff;text-decoration:none;padding:13px 36px;border-radius:10px;font-weight:700;font-size:14px;display:inline-block;">
    عرض الرسائل في لوحة التحكم ←
  </a>
</div>
@endsection
