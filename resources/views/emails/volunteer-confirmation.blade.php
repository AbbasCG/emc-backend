@extends('emails.base')
@section('title', 'تم استلام طلب التطوع')
@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#7C3AED,#A78BFA);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">🤝</span>
  </div>
  <h2 style="color:#4C1D95;margin:0;font-size:22px;font-weight:800;">أهلاً {{ $applicantName }}!</h2>
  <p style="color:#64748B;font-size:14px;margin:8px 0 0;">شكراً لاهتمامك بالتطوع في منصة EMC</p>
</div>

<p style="color:#475569;font-size:15px;line-height:1.8;margin:0 0 20px;">
  استلمنا طلب التطوع الخاص بك للانضمام إلى قسم <strong style="color:#7C3AED;">{{ $department }}</strong>.
  يسعدنا اهتمامك بدعم مجتمعنا التعليمي وسيتواصل معك فريق الموارد البشرية لدينا بعد مراجعة طلبك.
</p>

<div style="background:#F5F3FF;border-radius:12px;padding:20px 24px;margin:0 0 20px;border-right:4px solid #7C3AED;">
  <p style="margin:0 0 12px;color:#4C1D95;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل طلبك</p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;width:40%;">القسم المختار</td>
      <td style="color:#4C1D95;font-size:14px;font-weight:700;padding:4px 0;">{{ $department }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;">تاريخ التقديم</td>
      <td style="color:#4C1D95;font-size:14px;font-weight:700;padding:4px 0;">{{ $submittedAt }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:4px 0;">الحالة</td>
      <td style="padding:4px 0;">
        <span style="background:#DDD6FE;color:#5B21B6;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;">قيد المراجعة</span>
      </td>
    </tr>
  </table>
</div>

<div style="background:#FAF5FF;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
  <p style="margin:0 0 6px;color:#4C1D95;font-size:13px;font-weight:700;">📋 ما الذي يحدث بعد ذلك؟</p>
  <ul style="margin:8px 0 0;padding-right:20px;color:#64748B;font-size:13px;line-height:2;">
    <li>مراجعة طلبك من قِبل فريق الموارد البشرية</li>
    <li>التواصل معك لإجراء مقابلة تعريفية (عند الحاجة)</li>
    <li>إشعارك بقرار القبول خلال 3–7 أيام عمل</li>
  </ul>
</div>

<p style="color:#94A3B8;font-size:13px;text-align:center;margin-top:24px;">
  للاستفسار تواصل معنا على
  <a href="mailto:support@emc.test" style="color:#7C3AED;text-decoration:none;font-weight:600;">support@emc.test</a>
</p>
@endsection
