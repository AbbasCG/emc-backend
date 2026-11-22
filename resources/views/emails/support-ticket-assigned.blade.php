{{--
  "A support ticket was assigned to you" — for the staff member who now owns it.
  Same table-based RTL layout as ticket-assigned.blade.php.

  Data policy: number, subject, priority, who assigned it and when. Never the
  customer's message, email address or phone number.
--}}
@php
  $navy = '#0C2A4B';
  $blue = '#0077B6';
  $orange = '#F28C00';
  $font = "'IBM Plex Sans Arabic','Segoe UI',Tahoma,Arial,sans-serif";

  [$prioText, $prioBg] = match ($priority) {
    'urgent' => ['#B91C1C', '#FEF2F2'],
    'high'   => ['#C2410C', '#FFF7ED'],
    'medium' => ['#0369A1', '#EAF6FD'],
    default  => ['#475569', '#F1F5F9'],
  };

  $rows = array_filter([
    'رقم التذكرة'   => $ticketNumber,
    'أسندها'        => $assignedByName,
    'تاريخ الإسناد' => $assignedAt,
  ], fn ($v) => filled($v));
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>تم تعيين تذكرة دعم إليك: {{ $subjectText }}</title>
  <style>
    @media only screen and (max-width: 620px) {
      .container { width: 100% !important; }
      .px { padding-left: 20px !important; padding-right: 20px !important; }
      .btn a { display: block !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#F3F1EA;font-family:{!! $font !!};direction:rtl;text-align:right;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#F3F1EA;">
    تم تعيين تذكرة دعم إليك — {{ $subjectText }}
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" bgcolor="#F3F1EA" style="background-color:#F3F1EA;">
    <tr>
      <td align="center" style="padding:32px 12px;">
        <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="width:600px;max-width:600px;">
          <tr>
            <td align="center" bgcolor="{{ $navy }}" style="background-color:{{ $navy }};border-radius:16px 16px 0 0;padding:28px 24px 22px;">
              <div style="font-family:{!! $font !!};color:#FFFFFF;font-size:30px;font-weight:700;letter-spacing:4px;line-height:1.2;">EMC</div>
              <div style="font-family:{!! $font !!};color:#A6D6F2;font-size:13px;line-height:1.6;margin-top:4px;">مكتب الدعم الفني</div>
            </td>
          </tr>
          <tr>
            <td height="4" bgcolor="{{ $orange }}" style="background-color:{{ $orange }};height:4px;font-size:0;line-height:0;">&nbsp;</td>
          </tr>
          <tr>
            <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:36px 40px 12px;border-right:1px solid #E7E3DA;border-left:1px solid #E7E3DA;">

              @if ($priorityLabel)
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" dir="rtl" align="right">
                <tr>
                  <td bgcolor="{{ $prioBg }}" style="background-color:{{ $prioBg }};border-radius:999px;padding:6px 14px;font-family:{!! $font !!};font-size:13px;font-weight:700;color:{{ $prioText }};">
                    الأولوية: {{ $priorityLabel }}
                  </td>
                </tr>
              </table>
              <div style="clear:both;height:18px;line-height:18px;font-size:0;">&nbsp;</div>
              @endif

              <h1 style="margin:0 0 12px;font-family:{!! $font !!};font-size:24px;line-height:1.4;font-weight:700;color:{{ $navy }};text-align:right;">تم تعيين تذكرة دعم إليك</h1>
              <p style="margin:0 0 6px;font-family:{!! $font !!};font-size:15px;line-height:1.9;color:#27384B;text-align:right;">
                أهلاً <strong>{{ $recipientName }}</strong>،
              </p>
              <p style="margin:0 0 24px;font-family:{!! $font !!};font-size:15px;line-height:1.9;color:#475569;text-align:right;">
                أُسندت إليك تذكرة الدعم التالية. يرجى مراجعتها والرد على صاحبها من مكتب الدعم.
              </p>

              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl">
                <tr>
                  <td bgcolor="#FBFAF7" style="background-color:#FBFAF7;border:1px solid #E7E3DA;border-right:4px solid {{ $blue }};border-radius:12px;padding:20px 22px;">
                    <div style="font-family:{!! $font !!};font-size:12px;font-weight:700;color:#64748B;text-align:right;">موضوع التذكرة</div>
                    <div style="font-family:{!! $font !!};font-size:17px;font-weight:700;line-height:1.7;color:{{ $navy }};text-align:right;margin-top:4px;">{{ $subjectText }}</div>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="rtl" style="margin-top:16px;border-top:1px solid #E7E3DA;">
                      @foreach ($rows as $label => $value)
                      <tr>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:13px;color:#64748B;text-align:right;" width="38%">{{ $label }}</td>
                        <td style="padding:12px 0 0;font-family:{!! $font !!};font-size:14px;font-weight:700;color:#27384B;text-align:right;">
                          @if ($label === 'رقم التذكرة')<span dir="ltr" style="unicode-bidi:embed;">{{ $value }}</span>@else{{ $value }}@endif
                        </td>
                      </tr>
                      @endforeach
                    </table>
                  </td>
                </tr>
              </table>

              <table role="presentation" class="btn" cellpadding="0" cellspacing="0" border="0" dir="rtl" align="center" style="margin:28px auto 8px;">
                <tr>
                  <td align="center" bgcolor="{{ $blue }}" style="background-color:{{ $blue }};border-radius:10px;">
                    <a href="{{ $reviewUrl }}" target="_blank" style="display:inline-block;padding:14px 44px;font-family:{!! $font !!};font-size:16px;font-weight:700;line-height:1.4;color:#FFFFFF;text-decoration:none;border-radius:10px;">
                      مراجعة التذكرة
                    </a>
                  </td>
                </tr>
              </table>
              <p style="margin:14px 0 0;font-family:{!! $font !!};font-size:12px;line-height:1.8;color:#94A3B8;text-align:center;">
                تحتاج إلى تسجيل الدخول إلى حسابك لعرض التفاصيل.
              </p>
              <p style="margin:10px 0 0;font-family:{!! $font !!};font-size:12px;line-height:1.8;color:#94A3B8;text-align:center;word-break:break-all;">
                إذا لم يعمل الزر، انسخ هذا الرابط:<br>
                <a href="{{ $reviewUrl }}" style="color:{{ $blue }};text-decoration:none;"><span dir="ltr" style="unicode-bidi:embed;">{{ $reviewUrl }}</span></a>
              </p>
              <div style="height:24px;line-height:24px;font-size:0;">&nbsp;</div>
            </td>
          </tr>
          <tr>
            <td class="px" bgcolor="#FBFAF7" align="center" style="background-color:#FBFAF7;border:1px solid #E7E3DA;border-radius:0 0 16px 16px;padding:22px 40px;">
              <p style="margin:0;font-family:{!! $font !!};font-size:12px;line-height:1.8;color:#94A3B8;text-align:center;">
                وصلك هذا البريد لأن تذكرة دعم أُسندت إليك على منصة EMC. يمكنك إيقافه من «تفضيلات الإشعارات» في حسابك.<br>
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
