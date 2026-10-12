@extends('emails.base')
@section('title', "{$headline}: {$itemTitle}")
@section('content')
@php
  $isSuggestion = str_starts_with($kind, 'suggestion_');
  $intro = match ($kind) {
    'ticket_resolved' => 'تمت معالجة البلاغ الخاص بك وحلّه بنجاح.',
    'suggestion_approved' => 'تم اعتماد مقترحك من قبل الإدارة.',
    default => 'تمت إضافة مقترحك إلى النظام. شكرًا لمساهمتك في تطوير المنصة.',
  };
  $accent = $kind === 'suggestion_approved' ? '#2691C2' : '#22C55E';
@endphp
<h2 style="color:#22334A;margin-top:0;font-size:22px;font-weight:800;">{{ $headline }}</h2>
<p style="color:#475569;line-height:1.8;font-size:15px;">
  أهلاً <strong>{{ $recipientName }}</strong>، {{ $intro }}
</p>

<div style="background:#F8FAFC;border-radius:12px;padding:20px 24px;margin:24px 0;border-right:4px solid {{ $accent }};">
  <p style="margin:0;color:#64748B;font-size:12px;font-weight:700;">{{ $isSuggestion ? 'المقترح' : 'البلاغ' }}</p>
  <p style="margin:8px 0 0;color:#22334A;font-size:16px;font-weight:700;line-height:1.6;">{{ $itemTitle }}</p>
  <p style="margin:14px 0 0;color:#64748B;font-size:12px;font-weight:700;">الحالة الجديدة</p>
  <p style="margin:6px 0 0;color:{{ $accent }};font-size:16px;font-weight:800;">{{ $statusLabel }}</p>
</div>

<div style="text-align:center;margin:32px 0;">
  <a href="{{ $detailsUrl }}"
     style="background:#2691C2;color:#ffffff;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:15px;display:inline-block;">
    عرض التفاصيل ←
  </a>
</div>

<p style="color:#94A3B8;font-size:12px;line-height:1.7;margin:0;">
  يتطلب عرض التفاصيل تسجيل الدخول إلى حسابك على المنصة.
</p>
@endsection
