<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تم استلام طلبك</title>
<style>
  body { margin: 0; padding: 0; background: #F4F7FB; font-family: 'Tajawal', Arial, sans-serif; direction: rtl; }
  .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(34,51,74,0.10); }
  .header { background: linear-gradient(135deg, #22334A 0%, #1a2940 60%, #0F172A 100%); padding: 40px 36px; text-align: right; }
  .logo { font-size: 28px; font-weight: 900; color: #fff; letter-spacing: -0.5px; margin-bottom: 8px; }
  .header-sub { color: rgba(255,255,255,0.65); font-size: 13px; }
  .badge { display: inline-block; background: #2691C2; color: #fff; border-radius: 20px; padding: 4px 14px; font-size: 12px; font-weight: 700; margin-bottom: 20px; }
  .body { padding: 36px; text-align: right; color: #22334A; }
  .greeting { font-size: 22px; font-weight: 900; margin-bottom: 16px; }
  p { font-size: 15px; line-height: 1.8; color: #445; margin: 0 0 14px; }
  .info-box { background: #EEF7FC; border-right: 4px solid #2691C2; border-radius: 10px; padding: 16px 20px; margin: 24px 0; }
  .info-box p { margin: 0; color: #1a5270; font-size: 14px; }
  .steps { margin: 24px 0; }
  .step { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 14px; }
  .step-num { background: #2691C2; color: #fff; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 900; flex-shrink: 0; }
  .step-text { font-size: 14px; color: #445; line-height: 1.6; }
  .footer { background: #F4F7FB; padding: 24px 36px; text-align: center; }
  .footer p { font-size: 12px; color: #888; margin: 4px 0; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="logo">EMC</div>
    <div class="header-sub">منصة التحول الرقمي والتعليم التقني</div>
  </div>
  <div class="body">
    <div class="badge">برنامج سفراء التحول الرقمي</div>
    <div class="greeting">مرحباً {{ $applicantName }}،</div>
    <p>يسعدنا إخبارك بأننا استلمنا طلبك للانضمام إلى برنامج <strong>سفراء التحول الرقمي</strong> في منصة EMC.</p>

    <div class="info-box">
      <p>🔖 رقم الطلب: <strong>{{ $referenceNumber }}</strong></p>
      <p style="margin-top:8px;">📅 تاريخ تقديم الطلب: <strong>{{ $submittedAt }}</strong></p>
    </div>

    <p>سيقوم فريقنا بمراجعة طلبك وسنتواصل معك عبر البريد الإلكتروني في حال الحاجة لمزيد من المعلومات أو لإخبارك بنتيجة الطلب.</p>

    <div class="steps">
      <div class="step">
        <div class="step-num">١</div>
        <div class="step-text"><strong>مراجعة الطلب</strong> — يراجع فريقنا جميع البيانات والوثائق المرفقة.</div>
      </div>
      <div class="step">
        <div class="step-num">٢</div>
        <div class="step-text"><strong>التواصل معك</strong> — قد نتواصل معك لاستفسارات إضافية أو لجدولة مقابلة.</div>
      </div>
      <div class="step">
        <div class="step-num">٣</div>
        <div class="step-text"><strong>إخطارك بالنتيجة</strong> — ستصلك رسالة بريدية بقرار القبول أو بالخطوات التالية.</div>
      </div>
    </div>

    <p>شكراً لاهتمامك بالانضمام إلى مجتمع EMC ومساهمتك في نشر ثقافة التحول الرقمي.</p>
    <p>مع تحيات فريق EMC</p>
  </div>
  <div class="footer">
    <p>هذه الرسالة أُرسلت تلقائياً · لا تردّ عليها مباشرةً</p>
    <p>منصة EMC © {{ date('Y') }}</p>
  </div>
</div>
</body>
</html>
