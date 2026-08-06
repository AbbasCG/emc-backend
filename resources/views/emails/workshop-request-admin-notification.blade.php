@extends('emails.base')
@section('title', 'طلب برنامج تدريبي جديد')
@section('content')

<div style="text-align:center;margin-bottom:24px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#4338CA,#6366F1);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">📋</span>
  </div>
  <h2 style="color:#312E81;margin:0;font-size:21px;font-weight:800;">طلب برنامج تدريبي جديد</h2>
  <p style="color:#64748B;font-size:13px;margin:6px 0 0;">يتطلب مراجعتك</p>
</div>

<div style="background:#EEF2FF;border-radius:12px;padding:20px 24px;margin:0 0 16px;border-right:4px solid #6366F1;">
  <p style="margin:0 0 12px;color:#312E81;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">بيانات الطلب</p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;width:35%;">اسم الطالب</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:5px 0;">{{ $workshopRequest->requester_name ?? '—' }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">البريد الإلكتروني</td>
      <td style="padding:5px 0;">
        <a href="mailto:{{ $workshopRequest->requester_email }}" style="color:#4F46E5;font-weight:600;text-decoration:none;">{{ $workshopRequest->requester_email }}</a>
      </td>
    </tr>
    @if($workshopRequest->title)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">عنوان البرنامج</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:5px 0;">{{ $workshopRequest->title }}</td>
    </tr>
    @endif
    @if($workshopRequest->organization)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">الجهة / المؤسسة</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:5px 0;">{{ $workshopRequest->organization }}</td>
    </tr>
    @endif
    @if($workshopRequest->attendees_count)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">عدد المشاركين</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:5px 0;">{{ $workshopRequest->attendees_count }}</td>
    </tr>
    @endif
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">الحالة</td>
      <td style="padding:5px 0;">
        <span style="background:#C7D2FE;color:#3730A3;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;">{{ $workshopRequest->workflow_status ?? 'جديد' }}</span>
      </td>
    </tr>
  </table>
</div>

@if($workshopRequest->description)
<div style="background:#F8FAFC;border-radius:10px;padding:16px 20px;margin-bottom:20px;border:1px solid #E2E8F0;">
  <p style="margin:0 0 8px;color:#475569;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل البرنامج</p>
  <p style="margin:0;color:#334155;font-size:14px;line-height:1.8;">{{ $workshopRequest->description }}</p>
</div>
@endif

<div style="text-align:center;margin:24px 0;">
  <a href="{{ $reviewUrl }}"
     style="background:linear-gradient(135deg,#4338CA,#6366F1);color:#ffffff;text-decoration:none;padding:13px 36px;border-radius:10px;font-weight:700;font-size:14px;display:inline-block;">
    مراجعة الطلب في لوحة التحكم ←
  </a>
</div>
@endsection
