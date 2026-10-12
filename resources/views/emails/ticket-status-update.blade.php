{{--
  Ticket status update for the person who submitted it (resolved / approved / implemented).

  Standalone on purpose: it is table-based with inline styles and bgcolor
  attributes so Gmail, Outlook (Word engine) and Apple Mail all lay it out the
  same way, and it carries its own RTL + brand styling instead of the shared
  base layout. The Google Font link is a progressive enhancement; the font
  stack falls back to system Arabic faces where clients strip it.

  Data policy: title, reference number, status, update time and a link only.
  Never the resolution summary, internal notes or who handled the ticket.
--}}
@php
  $isSuggestion = str_starts_with($kind, 'suggestion_');
  $noun = $isSuggestion ? 'المقترح' : 'البلاغ';

  $intro = match ($kind) {
    'ticket_resolved' => 'يسعدنا إبلاغك بأنه تم حل البلاغ الذي قدّمته. شكرًا لك على مساعدتنا في تحسين المنصة.',
    'suggestion_approved' => 'تمت مراجعة مقترحك واعتماده من قبل الإدارة، وسنوافيك بأي مستجدات عند تنفيذه.',
    'suggestion_implemented' => 'تم تنفيذ مقترحك وإضافته إلى النظام. شكرًا لمساهمتك في تطوير المنصة.',
    default => 'تم تحديث حالة طلبك. يمكنك الاطلاع على آخر المستجدات من خلال الرابط أدناه.',
  };

  // Brand tokens (mirrors tailwind.config.js): navy / blue / orange / paper / line.
  $navy = '#0C2A4B';
  $blue = '#0077B6';
  $orange = '#F28C00';
  $success = '#15803D';
  $accent = $kind === 'suggestion_approved' ? $blue : $success;
  $accentSoft = $kind === 'suggestion_approved' ? '#EAF6FD' : '#ECFDF3';
  $font = "'IBM Plex Sans Arabic','Segoe UI',Tahoma,Arial,sans-serif";
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="color-scheme" content="light">
  <title>{{ $headline }}: {{ $itemTitle }}</title>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    @media only screen and (max-width: 620px) {
      .container { width: 100% !important; }
      .px { padding-left: 20px !important; padding-right: 20px !important; }
      .h1 { font-size: 22px !important; }
      .btn a { display: block !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#F3F1EA;font-family:{!! $font !!};direction:rtl;text-align:right;">

  {{-- Inbox preview text (hidden in the body) --}}
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#F3F1EA;">
    {{ $headline }} — {{ $itemTitle }}
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#F3F1EA" style="background-color:#F3F1EA;">
    <tr>
      <td align="center" style="padding:32px 12px;">

        <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="width:600px;max-width:600px;">

          {{-- Brand header --}}
          <tr>
            <td align="center" bgcolor="{{ $navy }}" style="background-color:{{ $navy }};border-radius:16px 16px 0 0;padding:28px 24px 22px;">
              <div style="font-family:{!! $font !!};color:#FFFFFF;font-size:30px;font-weight:700;letter-spacing:4px;line-height:1.2;">EMC</div>
              <div style="font-family:{!! $font !!};color:#A6D6F2;font-size:13px;line-height:1.6;margin-top:4px;">منصة التعلّم الرقمي</div>
            </td>
          </tr>
          <tr>
            <td height="4" bgcolor="{{ $orange }}" style="background-color:{{ $orange }};height:4px;font-size:0;line-height:0;">&nbsp;</td>
          </tr>

          {{-- Card --}}
          <tr>
            <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:36px 40px 12px;border-right:1px solid #E7E3DA;border-left:1px solid #E7E3DA;">

              {{-- Status chip --}}
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl" align="right">
                <tr>
                  <td bgcolor="{{ $accentSoft }}" style="background-color:{{ $accentSoft }};border-radius:999px;padding:6px 14px;font-family:{!! $font !!};font-size:13px;font-weight:700;color:{{ $accent }};">
                    &#10003;&nbsp; {{ $statusLabel }}
                  </td>
                </tr>
              </table>
              <div style="clear:both;height:18px;line-height:18px;font-size:0;">&nbsp;</div>

              <h1 class="h1" style="margin:0 0 12px;font-family:{!! $font !!};font-size:26px;line-height:1.4;font-weight:700;color:{{ $navy }};text-align:right;">{{ $headline }}</h1>

              <p style="margin:0 0 6px;font-family:{!! $font !!};font-size:15px;line-height:1.9;color:#27384B;text-align:right;">
                أهلاً <strong>{{ $recipientName }}</strong>،
              </p>
              <p style="margin:0 0 24px;font-family:{!! $font !!};font-size:15px;line-height:1.9;color:#475569;text-align:right;">
                {{ $intro }}
              </p>

              {{-- Summary card --}}
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl">
                <tr>
                  <td bgcolor="#FBFAF7" style="background-color:#FBFAF7;border:1px solid #E7E3DA;border-right:4px solid {{ $accent }};border-radius:12px;padding:20px 22px;">

                    <div style="font-family:{!! $font !!};font-size:12px;font-weight:700;color:#64748B;text-align:right;">{{ $noun }}</div>
                    <div style="font-family:{!! $font !!};font-size:17px;font-weight:700;line-height:1.7;color:{{ $navy }};text-align:right;margin-top:4px;">{{ $itemTitle }}</div>

                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin-top:16px;border-top:1px solid #E7E3DA;">
                      @if ($ticketNumber)
                      <tr>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:13px;color:#64748B;text-align:right;" width="38%">رقم {{ $isSuggestion ? 'المقترح' : 'البلاغ' }}</td>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:14px;font-weight:700;color:#27384B;text-align:right;"><span dir="ltr" style="unicode-bidi:embed;">{{ $ticketNumber }}</span></td>
                      </tr>
                      @endif
                      <tr>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:13px;color:#64748B;text-align:right;" width="38%">الحالة الحالية</td>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:14px;font-weight:700;color:{{ $accent }};text-align:right;">{{ $statusLabel }}</td>
                      </tr>
                      @if ($updatedAt)
                      <tr>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:13px;color:#64748B;text-align:right;" width="38%">آخر تحديث</td>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:14px;color:#27384B;text-align:right;">{{ $updatedAt }}</td>
                      </tr>
                      @endif
                    </table>

                  </td>
                </tr>
              </table>

              {{-- CTA (bulletproof: the table cell carries the colour for Outlook) --}}
              <table role="presentation" class="btn" cellpadding="0" cellspacing="0" border="0" dir="rtl" align="center" style="margin:30px auto 8px;">
                <tr>
                  <td align="center" bgcolor="{{ $blue }}" style="background-color:{{ $blue }};border-radius:10px;">
                    <a href="{{ $detailsUrl }}" target="_blank"
                       style="display:inline-block;padding:14px 44px;font-family:{!! $font !!};font-size:16px;font-weight:700;line-height:1.4;color:#FFFFFF;text-decoration:none;border-radius:10px;">
                      عرض التفاصيل
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:18px 0 0;font-family:{!! $font !!};font-size:12px;line-height:1.8;color:#94A3B8;text-align:center;">
                تحتاج إلى تسجيل الدخول إلى حسابك لعرض التفاصيل.
              </p>
              <p style="margin:10px 0 0;font-family:{!! $font !!};font-size:12px;line-height:1.8;color:#94A3B8;text-align:center;word-break:break-all;">
                إذا لم يعمل الزر، انسخ هذا الرابط:<br>
                <a href="{{ $detailsUrl }}" style="color:{{ $blue }};text-decoration:none;"><span dir="ltr" style="unicode-bidi:embed;">{{ $detailsUrl }}</span></a>
              </p>
              <div style="height:24px;line-height:24px;font-size:0;">&nbsp;</div>
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td class="px" bgcolor="#FBFAF7" align="center" style="background-color:#FBFAF7;border:1px solid #E7E3DA;border-radius:0 0 16px 16px;padding:22px 40px;">
              <p style="margin:0;font-family:{!! $font !!};font-size:13px;line-height:1.9;color:#475569;text-align:center;">
                هل تحتاج إلى مساعدة؟ تواصل مع فريق الدعم على
                <a href="mailto:support@edumc.nl" style="color:{{ $blue }};text-decoration:none;font-weight:700;">support@edumc.nl</a>
              </p>
              <p style="margin:8px 0 0;font-family:{!! $font !!};font-size:12px;line-height:1.8;color:#94A3B8;text-align:center;">
                هذا البريد أُرسل تلقائيًا، يُرجى عدم الرد عليه مباشرة.<br>
                © {{ date('Y') }} منصة EMC
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
