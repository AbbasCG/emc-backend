@extends('emails.base')
@section('title', 'إعادة تعيين كلمة المرور')
@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#475569,#94A3B8);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">🔑</span>
  </div>
  <h2 style="color:#1E293B;margin:0;font-size:22px;font-weight:800;">إعادة تعيين كلمة المرور</h2>
  <p style="color:#64748B;font-size:14px;margin:8px 0 0;">استلمنا طلباً لإعادة تعيين كلمة مرور حسابك</p>
</div>

<p style="color:#475569;font-size:15px;line-height:1.8;margin:0 0 20px;">
  أهلاً <strong style="color:#1E293B;">{{ $userName }}</strong>،
  <br>
  إذا كنت من طلب إعادة تعيين كلمة المرور، اضغط على الزر أدناه للمتابعة. إذا لم تطلب ذلك، يمكنك تجاهل هذا البريد وسيبقى حسابك آمناً.
</p>

<div style="text-align:center;margin:28px 0;">
  <a href="{{ $resetUrl }}"
     style="background:linear-gradient(135deg,#334155,#64748B);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    إعادة تعيين كلمة المرور ←
  </a>
</div>

<div style="background:#FEF2F2;border-radius:10px;padding:14px 18px;margin-bottom:20px;border-right:3px solid #EF4444;">
  <p style="margin:0 0 4px;color:#DC2626;font-size:13px;font-weight:700;">⚠️ تنبيه أمني</p>
  <ul style="margin:6px 0 0;padding-right:18px;color:#7F1D1D;font-size:13px;line-height:1.9;">
    <li>الرابط صالح لمدة <strong>{{ $expiresMins }} دقيقة</strong> فقط</li>
    <li>الرابط للاستخدام مرة واحدة فقط</li>
    <li>لن نطلب منك أبداً كلمة مرورك عبر البريد الإلكتروني</li>
  </ul>
</div>

<div style="background:#F8FAFC;border-radius:10px;padding:14px 18px;margin-bottom:8px;">
  <p style="margin:0;color:#64748B;font-size:12px;line-height:1.6;">
    إذا لم يعمل الزر، انسخ هذا الرابط والصقه في المتصفح:
    <br>
    <span style="color:#475569;word-break:break-all;font-size:11px;">{{ $resetUrl }}</span>
  </p>
</div>
@endsection
