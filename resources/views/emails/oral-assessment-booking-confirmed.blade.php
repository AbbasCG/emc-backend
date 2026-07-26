@extends('emails.base')
@section('title', 'تأكيد موعد المقابلة الشفهية')
@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#2691C2,#22334A);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">🎙️</span>
  </div>
  <h2 style="color:#22334A;margin:0;font-size:22px;font-weight:800;">
    {{ $isInstructor ? 'حجز جديد لمقابلة شفهية' : 'تم تأكيد موعد مقابلتك الشفهية' }}
  </h2>
  <p style="color:#64748B;font-size:14px;margin:8px 0 0;">
    أهلاً {{ $recipientName }}،
    {{ $isInstructor ? "قام الطالب {$otherPartyName} بحجز موعد مقابلة شفهية معك." : 'موعدك محجوز — إليك تفاصيل المقابلة.' }}
  </p>
</div>

<div style="background:#EFF6FB;border-radius:12px;padding:20px 24px;margin:0 0 20px;border-right:4px solid #2691C2;">
  <p style="margin:0 0 12px;color:#22334A;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الموعد</p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;width:40%;">الدورة</td>
      <td style="color:#22334A;font-size:14px;font-weight:700;padding:5px 0;">{{ $courseTitle }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">{{ $otherPartyLabel }}</td>
      <td style="color:#22334A;font-size:14px;font-weight:700;padding:5px 0;">{{ $otherPartyName }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">التاريخ</td>
      <td style="color:#22334A;font-size:14px;font-weight:700;padding:5px 0;">{{ $dateLabel }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">الوقت</td>
      <td style="color:#22334A;font-size:14px;font-weight:700;padding:5px 0;" dir="ltr">{{ $startTimeLabel }} – {{ $endTimeLabel }} ({{ $timezoneLabel }})</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">رقم الحجز</td>
      <td style="color:#22334A;font-size:14px;font-weight:700;padding:5px 0;">#{{ $bookingReference }}</td>
    </tr>
  </table>
</div>

@if($meetingLink)
<div style="text-align:center;margin:28px 0;">
  <a href="{{ $meetingLink }}"
     style="background:linear-gradient(135deg,#2691C2,#22334A);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    الانضمام إلى الاجتماع ←
  </a>
</div>
@else
<div style="background:#FFF7ED;border-radius:12px;padding:16px 20px;margin:0 0 20px;text-align:center;">
  <p style="color:#9A5B13;font-size:13px;margin:0;font-weight:600;">سيتم إضافة رابط الاجتماع قريباً — ستصلك رسالة بمجرد توفره.</p>
</div>
@endif

<div style="text-align:center;margin:24px 0 8px;">
  <a href="{{ $dashboardUrl }}" style="color:#2691C2;text-decoration:none;font-size:13px;font-weight:700;">
    عرض التفاصيل في لوحة التحكم ←
  </a>
</div>

<div style="border-top:1px solid #E2E8F0;margin-top:20px;padding-top:16px;">
  <p style="color:#94A3B8;font-size:11px;line-height:1.8;margin:0;">
    <strong>سياسة الإلغاء وإعادة الجدولة:</strong>
    يمكن التواصل مع {{ $isInstructor ? 'الطالب' : 'المدرب' }} عبر منصة EMC لإعادة جدولة الموعد أو إلغائه قبل وقت المقابلة.
    أي تغيير في الموعد أو الرابط سيصلك تلقائياً عبر البريد الإلكتروني وإشعار داخل المنصة.
  </p>
</div>

@endsection
