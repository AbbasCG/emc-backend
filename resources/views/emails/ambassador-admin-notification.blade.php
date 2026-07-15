<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>طلب سفير جديد</title>
<style>
  body { margin: 0; padding: 0; background: #F4F7FB; font-family: Arial, sans-serif; direction: rtl; }
  .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(34,51,74,0.10); }
  .header { background: linear-gradient(135deg, #22334A 0%, #1a2940 60%, #0F172A 100%); padding: 32px 36px; }
  .logo { font-size: 26px; font-weight: 900; color: #fff; }
  .header-sub { color: rgba(255,255,255,0.6); font-size: 13px; margin-top: 4px; }
  .alert-bar { background: #2691C2; color: #fff; padding: 12px 36px; font-size: 14px; font-weight: 700; }
  .body { padding: 36px; color: #22334A; }
  .greeting { font-size: 18px; font-weight: 700; margin-bottom: 16px; }
  p { font-size: 14px; line-height: 1.8; color: #445; margin: 0 0 12px; }
  .detail-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
  .detail-table td { padding: 10px 14px; border-bottom: 1px solid #E2E8F0; font-size: 14px; }
  .detail-table td:first-child { color: #888; font-weight: 600; width: 40%; }
  .detail-table td:last-child { color: #22334A; font-weight: 700; }
  .cta { display: block; background: #2691C2; color: #fff !important; text-decoration: none; text-align: center; padding: 14px 32px; border-radius: 10px; font-weight: 700; font-size: 15px; margin: 28px 0; }
  .footer { background: #F4F7FB; padding: 20px 36px; text-align: center; }
  .footer p { font-size: 12px; color: #888; margin: 4px 0; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="logo">EMC</div>
    <div class="header-sub">لوحة التحكم — إشعار إداري</div>
  </div>
  <div class="alert-bar">⭐ طلب انضمام جديد: برنامج سفراء التحول الرقمي</div>
  <div class="body">
    <div class="greeting">مرحباً {{ $adminName }}،</div>
    <p>تم استلام طلب انضمام جديد إلى برنامج سفراء التحول الرقمي. يُرجى مراجعته في أقرب وقت.</p>

    <table class="detail-table">
      <tr><td>الاسم الكامل</td><td>{{ $applicantName }}</td></tr>
      <tr><td>البريد الإلكتروني</td><td>{{ $applicantEmail }}</td></tr>
      <tr><td>الجامعة</td><td>{{ $university }}</td></tr>
      <tr><td>التخصص</td><td>{{ $major }}</td></tr>
      <tr><td>الدولة</td><td>{{ $country }}</td></tr>
      <tr><td>تاريخ التقديم</td><td>{{ $submittedAt }}</td></tr>
    </table>

    <a href="{{ $reviewUrl }}" class="cta">مراجعة الطلب في لوحة التحكم</a>
  </div>
  <div class="footer">
    <p>هذا الإشعار أُرسل تلقائياً إلى مدراء المنصة ومسؤولي الموارد البشرية.</p>
    <p>منصة EMC © {{ date('Y') }}</p>
  </div>
</div>
</body>
</html>
