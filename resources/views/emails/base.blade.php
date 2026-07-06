<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'إشعار من منصة EMC')</title>
</head>
<body style="margin:0;padding:0;background:#F1F5F9;font-family:'Segoe UI',Tahoma,Arial,sans-serif;direction:rtl;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9;padding:40px 20px;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;max-width:600px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

          {{-- Header --}}
          <tr>
            <td style="background:linear-gradient(135deg,#22334A 0%,#2691C2 100%);padding:32px 40px;text-align:center;">
              <div style="color:#ffffff;font-size:32px;font-weight:900;letter-spacing:3px;text-shadow:0 2px 8px rgba(0,0,0,0.15);">EMC</div>
              <div style="color:rgba(255,255,255,0.75);font-size:13px;margin-top:6px;letter-spacing:1px;">منصة التعلم الرقمي</div>
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td style="padding:40px 40px 32px;">
              @yield('content')
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="background:#F8FAFC;border-top:1px solid #E2E8F0;padding:24px 40px;text-align:center;">
              <p style="color:#94A3B8;font-size:12px;margin:0;line-height:1.6;">
                هذا البريد أُرسل تلقائياً، لا تردّ عليه مباشرةً.
              </p>
              <p style="color:#94A3B8;font-size:12px;margin:8px 0 0;line-height:1.6;">
                © {{ date('Y') }} EMC Platform &nbsp;|&nbsp;
                <a href="mailto:support@edumc.nl" style="color:#2691C2;text-decoration:none;">support@edumc.nl</a>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
