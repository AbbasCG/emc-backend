<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تحديث حالة الطلب</title>
<style>
  body { margin: 0; padding: 0; background: #F4F7FB; font-family: Arial, sans-serif; direction: rtl; }
  .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(34,51,74,0.10); }
  .header { background: linear-gradient(135deg, #22334A 0%, #1a2940 60%, #0F172A 100%); padding: 32px 36px; }
  .logo { font-size: 26px; font-weight: 900; color: #fff; }
  .body { padding: 36px; color: #22334A; }
  .greeting { font-size: 20px; font-weight: 900; margin-bottom: 16px; }
  p { font-size: 14px; line-height: 1.8; color: #445; margin: 0 0 14px; }
  .status-badge { display: inline-block; padding: 8px 20px; border-radius: 20px; font-weight: 900; font-size: 16px; margin: 20px 0; }
  .status-approved  { background: #d1fae5; color: #065f46; }
  .status-rejected  { background: #fee2e2; color: #991b1b; }
  .status-interview { background: #dbeafe; color: #1e40af; }
  .status-waitlisted{ background: #fef3c7; color: #92400e; }
  .status-default   { background: #EEF7FC; color: #1a5270; }
  .reason-box { background: #F8FBFE; border-right: 4px solid #EC943C; border-radius: 10px; padding: 14px 18px; margin: 20px 0; }
  .reason-box p { margin: 0; font-size: 14px; color: #445; }
  .interview-box { background: #EEF7FC; border-right: 4px solid #2691C2; border-radius: 10px; padding: 14px 18px; margin: 20px 0; }
  .footer { background: #F4F7FB; padding: 20px 36px; text-align: center; }
  .footer p { font-size: 12px; color: #888; margin: 4px 0; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="logo">EMC — برنامج سفراء التحول الرقمي</div>
  </div>
  <div class="body">
    <div class="greeting">مرحباً {{ $applicantName }}،</div>
    <p>نُحيطك علماً بأن حالة طلبك في برنامج سفراء التحول الرقمي قد تحدّثت:</p>

    @php
      $badgeClass = match($newStatus) {
        'approved'             => 'status-approved',
        'rejected'             => 'status-rejected',
        'interview_scheduled'  => 'status-interview',
        'waitlisted'           => 'status-waitlisted',
        default                => 'status-default',
      };
    @endphp

    <div class="status-badge {{ $badgeClass }}">{{ $statusLabel }}</div>

    @if($newStatus === 'approved')
      <p>تهانينا! تمت الموافقة على طلبك للانضمام إلى برنامج سفراء التحول الرقمي. سيتواصل معك أحد أعضاء الفريق لإخبارك بالخطوات التالية.</p>
    @elseif($newStatus === 'rejected')
      <p>نشكرك على اهتمامك. للأسف، لا يتوافق طلبك مع متطلباتنا الحالية للبرنامج. نشجعك على إعادة التقديم في المستقبل.</p>
    @elseif($newStatus === 'interview_scheduled')
      <p>يسعدنا دعوتك لإجراء مقابلة معنا. يُرجى الانتظار حتى يتواصل معك فريقنا لتحديد موعد المقابلة.</p>
      @if($interviewAt)
        <div class="interview-box">
          <p>📅 موعد المقابلة المقترح: <strong>{{ $interviewAt }}</strong></p>
        </div>
      @endif
    @elseif($newStatus === 'waitlisted')
      <p>تم وضع طلبك على قائمة الانتظار. سنتواصل معك في حال توافر مقاعد.</p>
    @elseif($newStatus === 'under_review')
      <p>طلبك حالياً قيد المراجعة الدقيقة من قِبل فريقنا. سنتواصل معك قريباً.</p>
    @else
      <p>تم تحديث حالة طلبك. سنتواصل معك لمزيد من التفاصيل إن لزم الأمر.</p>
    @endif

    @if($reason)
      <div class="reason-box">
        <p><strong>ملاحظة إضافية:</strong> {{ $reason }}</p>
      </div>
    @endif

    <p>شكراً لاهتمامك بمنصة EMC وبرنامج سفراء التحول الرقمي.</p>
    <p>مع أطيب التحيات،<br>فريق EMC</p>
  </div>
  <div class="footer">
    <p>هذه الرسالة أُرسلت تلقائياً · لا تردّ عليها مباشرةً</p>
    <p>منصة EMC © {{ date('Y') }}</p>
  </div>
</div>
</body>
</html>
