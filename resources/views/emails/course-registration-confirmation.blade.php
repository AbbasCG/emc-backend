<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تأكيد تسجيل الدورة</title>
</head>

<body style="margin:0; padding:0; background:#f4f7fb; font-family:'Segoe UI', Tahoma, Arial, sans-serif; direction:rtl; color:#22334a;">

@php
    $course = $registration->course;
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
    $courseUrl = $course?->slug ? $frontendUrl . '/courses/' . $course->slug : $frontendUrl . '/courses';
    $isPaid = ($course->type ?? null) === 'paid';
    $logoUrl = asset('logo.png');
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:40px 16px;">
<tr>
<td align="center">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:650px;">

<!-- HEADER -->
<tr>
<td align="center" style="background:#22334a; padding:36px; border-radius:20px 20px 0 0;">

    <table width="100%">
        <tr>
            <td align="center">
                <img src="{{ $logoUrl }}" width="130" style="display:block; margin:0 auto 10px;">
            </td>
        </tr>

        <tr>
            <td align="center">
                <span style="background:#2f5574; color:#fff; padding:8px 16px; border-radius:20px; font-size:13px; font-weight:bold;">
                    منصة تعليمية رقمية عالمية
                </span>
            </td>
        </tr>

        <tr>
            <td align="center" style="padding-top:20px;">
                <h1 style="color:#fff; margin:0; font-size:26px;">
                    تأكيد تسجيلك في الدورة
                </h1>
            </td>
        </tr>

        <tr>
            <td align="center" style="padding-top:10px;">
                <div style="width:60px; height:4px; background:#ec943c;"></div>
            </td>
        </tr>
    </table>

</td>
</tr>

<!-- BODY -->
<tr>
<td style="background:#ffffff; padding:30px; border-radius:0 0 20px 20px;">

<!-- SUCCESS -->
<table width="100%">
<tr>
<td align="center">

<div style="width:70px; height:70px; background:#e8f8ef; border-radius:50%; line-height:70px; font-size:32px; color:#22a66a;">
✓
</div>

<h2 style="margin-top:15px;">تم استلام تسجيلك بنجاح</h2>

<p style="color:#64748b;">
مرحباً <strong style="color:#2691C2;">{{ $registration->full_name }}</strong><br>
تم تسجيلك بنجاح في الدورة
</p>

</td>
</tr>
</table>

<!-- COURSE CARD -->
<table width="100%" style="margin-top:25px; border:1px solid #e7edf3; border-radius:12px;">
<tr>
<td style="padding:20px;">

<h3 style="margin:0;">{{ $course->title }}</h3>

<span style="display:inline-block; margin-top:10px; padding:6px 12px; border-radius:20px; background:{{ $isPaid ? '#ec943c' : '#2691C2' }}; color:#fff;">
{{ $isPaid ? 'مدفوعة' : 'مجانية' }}
</span>

</td>
</tr>

<tr>
<td style="padding:15px; border-top:1px solid #e7edf3;">

<strong>المُدرّب:</strong>
{{ $course->instructor?->name ?? $course->instructor_name ?? 'EMC' }}

<br><br>

<strong>المدة:</strong>
{{ $course->duration ?? 'غير محدد' }}

<br><br>

<strong>تاريخ البدء:</strong>
{{ !empty($course->start_date) ? \Carbon\Carbon::parse($course->start_date)->format('Y-m-d') : 'غير محدد' }}

<br><br>

<strong>الوقت:</strong>
{{ $course->study_time ?? 'غير محدد' }}

<br><br>

<strong>طريقة الحضور:</strong>
{{ !empty($course->is_online) ? 'أونلاين' : ($course->location ?? 'حضوري') }}

</td>
</tr>
</table>

<!-- REGISTRATION DATA -->
<table width="100%" style="margin-top:20px; border:1px solid #e7edf3; border-radius:12px;">
<tr>
<td style="padding:20px;">

<h3 style="margin:0 0 10px;">بيانات التسجيل</h3>

<strong>الاسم:</strong> {{ $registration->full_name }}<br>
<strong>البريد:</strong> {{ $registration->email }}<br>
<strong>الجوال:</strong> {{ $registration->phone }}<br>
<strong>المدينة:</strong> {{ $registration->city }}

</td>
</tr>
</table>

<!-- CTA -->
<table width="100%" style="margin-top:25px;">
<tr>
<td align="center">

<a href="{{ $courseUrl }}"
style="background:#ec943c; color:#fff; padding:14px 30px; text-decoration:none; border-radius:10px; font-weight:bold;">
عرض تفاصيل الدورة
</a>

</td>
</tr>
</table>

<!-- FOOTER -->
<p style="margin-top:25px; text-align:center; color:#6b7280;">
للاستفسار: support@emc.edu
</p>

</td>
</tr>

<tr>
<td align="center" style="padding-top:15px; font-size:12px; color:#9aa4b2;">
© {{ date('Y') }} EMC Platform
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>