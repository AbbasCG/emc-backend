@extends('emails.base')
@section('title', 'توثيق البريد الإلكتروني لحسابك في EMC')
@section('content')

<div style="text-align:center;margin-bottom:26px;">
  <div style="width:64px;height:64px;background:#EAF6FD;border-radius:50%;display:inline-block;line-height:64px;margin-bottom:14px;">
    <span style="font-size:28px;line-height:64px;">&#9993;</span>
  </div>
  <h2 style="color:#0C2A4B;margin:0;font-size:22px;font-weight:800;">يرجى توثيق بريدك الإلكتروني</h2>
</div>

<p style="color:#27384B;line-height:1.9;font-size:15px;margin:0 0 6px;">
  أهلاً <strong>{{ $userName }}</strong>،
</p>
<p style="color:#475569;line-height:1.9;font-size:15px;margin:0 0 24px;">
  تم إنشاء حسابك في منصة EMC. يرجى توثيق بريدك الإلكتروني لتأكيد ملكيتك للحساب.
</p>

<div style="text-align:center;margin:28px 0;">
  <a href="{{ $verifyUrl }}"
     style="background:#0077B6;color:#ffffff;text-decoration:none;padding:14px 44px;border-radius:10px;font-weight:700;font-size:16px;display:inline-block;">
    توثيق البريد الإلكتروني
  </a>
</div>

<div style="background:#FBFAF7;border:1px solid #E7E3DA;border-right:4px solid #F28C00;border-radius:12px;padding:14px 18px;margin:0 0 22px;">
  <p style="margin:0;color:#27384B;font-size:13px;line-height:1.8;">
    هذا الرابط صالح لمدة <strong>{{ $expiresText }}</strong>. إذا انتهت صلاحيته يمكنك طلب رابط جديد من إدارة المنصة.
  </p>
</div>

<p style="color:#64748B;font-size:12px;line-height:1.8;margin:0 0 6px;text-align:center;">
  إذا لم يعمل الزر، انسخ الرابط التالي والصقه في المتصفح:
</p>
<p style="margin:0 0 24px;text-align:center;word-break:break-all;">
  <a href="{{ $verifyUrl }}" style="color:#0077B6;font-size:12px;text-decoration:none;"><span dir="ltr" style="unicode-bidi:embed;">{{ $verifyUrl }}</span></a>
</p>

<div style="background:#F8FAFC;border-radius:10px;padding:14px 18px;text-align:center;">
  <p style="margin:0;color:#64748B;font-size:13px;line-height:1.7;">
    إذا لم تقم بإنشاء هذا الحساب، يمكنك تجاهل هذه الرسالة.
  </p>
</div>
@endsection
