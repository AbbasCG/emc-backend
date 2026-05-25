<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>كلمة المرور المؤقتة</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .card { background: #fff; border-radius: 8px; max-width: 520px; margin: 0 auto; padding: 36px; }
        .label { color: #555; font-size: 14px; margin-bottom: 4px; }
        .password-box { background: #f0f4ff; border: 1px solid #c7d3f0; border-radius: 6px; padding: 14px 20px; font-size: 22px; font-family: monospace; letter-spacing: 2px; text-align: center; margin: 16px 0; }
        .warning { color: #c0392b; font-size: 13px; margin-top: 12px; }
        .footer { font-size: 12px; color: #999; margin-top: 28px; text-align: center; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="margin-top:0">مرحباً {{ $user->name }}</h2>
        <p>تم إنشاء حسابك على منصة <strong>{{ config('app.name') }}</strong>.</p>
        <p class="label">كلمة المرور المؤقتة الخاصة بك:</p>
        <div class="password-box">{{ $temporaryPassword }}</div>
        <p>يُرجى تسجيل الدخول وتغيير كلمة المرور فوراً.</p>
        <p class="warning">⚠️ هذه الكلمة مؤقتة ولأسباب أمنية لن تُعرض مرة أخرى. لا تشاركها مع أي شخص.</p>
        <div class="footer">{{ config('app.name') }} — هذا البريد أُرسل تلقائياً، لا تردّ عليه.</div>
    </div>
</body>
</html>
