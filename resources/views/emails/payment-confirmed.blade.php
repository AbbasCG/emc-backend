@extends('emails.base')
@section('title', 'تأكيد الدفع والتسجيل')
@section('content')

<div style="text-align:center;margin-bottom:28px;">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#059669,#10B981);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
    <span style="font-size:28px;line-height:1;">✅</span>
  </div>
  <h2 style="color:#064E3B;margin:0;font-size:22px;font-weight:800;">تم تأكيد دفعتك بنجاح!</h2>
  <p style="color:#64748B;font-size:14px;margin:8px 0 0;">أهلاً {{ $studentName }}، تسجيلك مكتمل والدورة جاهزة</p>
</div>

<div style="background:#ECFDF5;border-radius:12px;padding:20px 24px;margin:0 0 20px;border-right:4px solid #10B981;">
  <p style="margin:0 0 12px;color:#065F46;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">تفاصيل الدفع</p>
  <table style="width:100%;border-collapse:collapse;">
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;width:40%;">الدورة التدريبية</td>
      <td style="color:#064E3B;font-size:14px;font-weight:700;padding:5px 0;">{{ $courseTitle }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">رقم التسجيل</td>
      <td style="color:#064E3B;font-size:14px;font-weight:700;padding:5px 0;">#{{ $registrationId }}</td>
    </tr>
    @if($amount)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">المبلغ المدفوع</td>
      <td style="padding:5px 0;">
        <span style="background:#D1FAE5;color:#065F46;padding:4px 12px;border-radius:6px;font-size:15px;font-weight:800;">{{ $amount }} {{ $currency }}</span>
      </td>
    </tr>
    @endif
    @if($paymentMethod)
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">طريقة الدفع</td>
      <td style="color:#064E3B;font-size:14px;font-weight:700;padding:5px 0;">{{ $paymentMethod }}</td>
    </tr>
    @endif
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">تاريخ الدفع</td>
      <td style="color:#064E3B;font-size:14px;font-weight:700;padding:5px 0;">{{ $paidAt }}</td>
    </tr>
    <tr>
      <td style="color:#64748B;font-size:13px;padding:5px 0;">الحالة</td>
      <td style="padding:5px 0;">
        <span style="background:#BBF7D0;color:#14532D;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;">مُسجَّل ✓</span>
      </td>
    </tr>
  </table>
</div>

<div style="text-align:center;margin:28px 0;">
  <a href="{{ $dashboardUrl }}"
     style="background:linear-gradient(135deg,#059669,#10B981);color:#ffffff;text-decoration:none;padding:14px 40px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    ابدأ التعلم الآن ←
  </a>
</div>

<p style="color:#94A3B8;font-size:12px;text-align:center;margin-top:16px;">
  احتفظ بهذا البريد مرجعاً للدفع. للمساعدة:
  <a href="mailto:support@edumc.nl" style="color:#059669;text-decoration:none;">support@edumc.nl</a>
</p>
@endsection
