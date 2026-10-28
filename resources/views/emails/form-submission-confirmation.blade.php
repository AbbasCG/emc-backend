@extends('emails.base')
@section('title', 'تم استلام ردك')
@section('content')
{{-- Data policy: the form title, the response number and the time only. Never an answer. --}}
<h2 style="color:#22334A;margin:0 0 8px;font-size:22px;font-weight:800;">
  @if($recipientName !== '')شكراً {{ $recipientName }}،@else شكراً لك،@endif
</h2>
<p style="color:#475569;font-size:15px;line-height:1.8;margin:0 0 20px;">
  وصلنا ردك على النموذج <strong style="color:#22334A;">«{{ $formTitle }}»</strong>، وسيطّلع عليه الفريق المسؤول.
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F8FB;border-radius:12px;border-right:4px solid #2691C2;margin:0 0 20px;">
  <tr>
    <td style="padding:18px 22px;">
      <p style="margin:0 0 8px;color:#73777B;font-size:13px;"><strong style="color:#22334A;">رقم الرد:</strong> {{ $submissionId }}</p>
      <p style="margin:0;color:#73777B;font-size:13px;"><strong style="color:#22334A;">وقت الاستلام:</strong> {{ $submittedAt }}</p>
    </td>
  </tr>
</table>

<p style="color:#94A3B8;font-size:12px;line-height:1.7;margin:0;">
  احتفظ برقم الرد إن احتجت إلى التواصل بشأنه. إن لم تكن أنت من أرسل هذا الرد فيمكنك تجاهل هذه الرسالة.
</p>
@endsection
