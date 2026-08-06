@extends('emails.base')
@section('title', 'طلب تطوع جديد')
@section('content')

<div style="text-align:center;margin-bottom:24px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#4F46E5,#818CF8);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">🙋</span>
  </div>
  <h2 style="color:#312E81;margin:0;font-size:21px;font-weight:800;">طلب تطوع جديد</h2>
  <p style="color:#64748B;font-size:13px;margin:6px 0 0;">{{ $submittedAt }}</p>
</div>

<div style="background:#EEF2FF;border-radius:12px;padding:20px 24px;margin:0 0 16px;border-right:4px solid #6366F1;">
  <p style="margin:0 0 12px;color:#312E81;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">بيانات المتقدم</p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;width:35%;">الاسم الكامل</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:5px 0;">{{ $applicantName }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">البريد الإلكتروني</td>
      <td style="padding:5px 0;">
        <a href="mailto:{{ $applicantEmail }}" style="color:#4F46E5;font-weight:600;text-decoration:none;">{{ $applicantEmail }}</a>
      </td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">الهاتف</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:5px 0;">{{ $applicantPhone }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">القسم المختار</td>
      <td style="padding:5px 0;">
        <span style="background:#C7D2FE;color:#3730A3;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;">{{ $department }}</span>
      </td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">مستوى الخبرة</td>
      <td style="color:#1E3A5F;font-size:14px;font-weight:700;padding:5px 0;">{{ $experienceLevel }}</td>
    </tr>
    @if($skills)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">المهارات</td>
      <td style="color:#334155;font-size:13px;padding:5px 0;line-height:1.6;">{{ $skills }}</td>
    </tr>
    @endif
  </table>
</div>

<div style="text-align:center;margin:24px 0;">
  <a href="{{ $reviewUrl }}"
     style="background:linear-gradient(135deg,#4F46E5,#6366F1);color:#ffffff;text-decoration:none;padding:13px 36px;border-radius:10px;font-weight:700;font-size:14px;display:inline-block;">
    مراجعة الطلب ←
  </a>
</div>
@endsection
