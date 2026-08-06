{{--
    EMC Default Certificate Template — Bilingual (Arabic/English), A4 Landscape

    $cfg  → CertificateTemplate designer config array
    $data → Dynamic certificate content array

    Partials:
      certificates.components.styles     (CSS — browser / PDF modes)
      certificates.components.watermarks (SVG background arcs)
      certificates.components.signatures (footer signatures + seal)
--}}
@php
    $cfg  = $cfg  ?? [];
    $data = $data ?? [];

    $primary   = $cfg['color_primary']   ?? '#0D2741';
    $secondary = $cfg['color_secondary'] ?? '#F57C00';
    $accent    = $cfg['color_accent']    ?? '#009DDB';
    $textColor = $cfg['color_text']      ?? '#1A1A1A';
    $fontEn    = $cfg['font_english']    ?? 'Poppins';
    $fontAr    = $cfg['font_arabic']     ?? 'Cairo';

    $descEn = $data['description_en'] ?? 'This certificate is awarded as part of the <strong>EMC Intensive Summer Program for Artificial Intelligence 2026</strong>, organized by ' . ($cfg['org_name_en'] ?? 'Educational Mastar Central') . ' over one full month (20 June – 20 July 2026), comprising eight applied workshops totaling 16 training hours.';
    $descAr = $data['description_ar'] ?? 'يُمنح هذه الشهادة ضمن المعسكر الصيفي المكثف لتعلم الذكاء الاصطناعي ٢٠٢٦، الذي نظّمه ' . ($cfg['org_name_ar'] ?? 'مركز ماستر التعليمي') . ' على مدى شهر كامل (٢٠ يونيو – ٢٠ يوليو ٢٠٢٦)، عبر ثماني ورش تطبيقية بإجمالي ١٦ ساعة تدريبية.';
@endphp
<!DOCTYPE html>
<html lang="ar" dir="ltr">
<head>
    <meta charset="UTF-8">
    <title>{{ $data['participant_name'] ?? 'Certificate' }}</title>
    @include('certificates.components.styles')
</head>
<body>
<div class="cert-page">

    {{-- ══ BACKGROUND DECORATION ════════════════════════════════════════════════ --}}
    @include('certificates.components.watermarks')

    {{-- ══ CONTENT LAYER ══════════════════════════════════════════════════════════ --}}
    <div class="cert-content">

        {{-- ── HEADER: Badges + Logos ─────────────────────────────────────────── --}}
        @include('certificates.components.header')

        {{-- ── PRESENTER BLOCK (stacked: EN line then AR line, centered) ─────── --}}
        <div class="presenter-block">
            <div class="presenter-en">{{ $data['presenter_en'] ?? 'THIS CERTIFICATE IS PRESENTED TO' }}</div>
            <div class="presenter-ar">{{ $data['presenter_ar'] ?? 'تشهد إدارة المعسكر الصيفي للذكاء الاصطناعي ٢٠٢٦ بأن' }}</div>
        </div>

        {{-- ── PARTICIPANT NAME ────────────────────────────────────────────────── --}}
        <div class="name-section">
            <div class="participant-name">
                {{ $data['participant_name'] ?? '[ اسم المشارك / Participant Name ]' }}
            </div>
            <span class="name-underline"></span>
        </div>

        {{-- ── ORANGE SEPARATOR ────────────────────────────────────────────────── --}}
        <div class="orange-separator"></div>

        {{-- ── TWO-COLUMN MAIN CONTENT ─────────────────────────────────────────── --}}
        <div class="main-content-table">
            <div class="main-content-row">

                {{-- LEFT: English --}}
                <div class="col-en-cell">
                    <div class="cert-main-title-en">CERTIFICATE</div>
                    <div class="cert-sub-title-en">OF&nbsp;ATTENDANCE</div>
                    <div class="cert-intro-en">
                        has attended the <em>{{ $data['workshop_ordinal_en'] ?? 'first' }}</em> workshop of the camp, titled:
                    </div>
                    <div class="course-title-en">
                        {{ $data['course_title_en'] ?? 'Introduction to Artificial Intelligence – The Complete Map' }}
                    </div>
                </div>

                {{-- DIVIDER --}}
                <div class="col-divider-cell">
                    <span class="col-divider-line"></span>
                </div>

                {{-- RIGHT: Arabic --}}
                <div class="col-ar-cell">
                    <div class="cert-main-title-ar">شهادة حضور</div>
                    <div class="cert-sub-title-ar">{{ $data['workshop_number_ar'] ?? 'الورشة الأولى' }}</div>
                    <div class="cert-intro-ar">
                        قد حضر/حضرت الورشة <strong>{{ $data['workshop_ordinal_ar'] ?? 'الأولى' }}</strong> من المعسكر، وهي بعنوان:
                    </div>
                    <div class="course-title-ar">
                        {{ $data['course_title_ar'] ?? 'مُدخل إلى الذكاء الاصطناعي – الخريطة الكاملة' }}
                    </div>
                </div>

            </div>
        </div>

        {{-- ── DATE / HOURS ROW ────────────────────────────────────────────────── --}}
        <div class="date-row-wrapper">
            <div class="date-divider-line"></div>
            <div class="date-row-table">
                <div class="date-row-inner">
                    <div class="date-en-cell">
                        <span class="date-item date-item-en">
                            <svg class="date-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="1" y="3" width="14" height="12" rx="1.5" stroke="{{ $primary }}" stroke-width="1.2"/>
                                <line x1="1" y1="6.5" x2="15" y2="6.5" stroke="{{ $primary }}" stroke-width="1"/>
                                <line x1="5"  y1="1.5" x2="5"  y2="4.5" stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                                <line x1="11" y1="1.5" x2="11" y2="4.5" stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                            </svg>
                            {{ $data['date_en'] ?? 'Saturday, 20 June 2026' }}
                        </span>
                        <span class="date-pipe"></span>
                        <span class="date-item date-item-en">
                            <svg class="date-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="8" cy="8" r="6.5" stroke="{{ $primary }}" stroke-width="1.2"/>
                                <line x1="8" y1="4.5" x2="8" y2="8.5"   stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                                <line x1="8" y1="8.5" x2="10.5" y2="10" stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                            </svg>
                            {{ $data['hours_en'] ?? '2 Training Hours · Online' }}
                        </span>
                    </div>
                    <div class="date-ar-cell">
                        <span class="date-item date-item-ar">
                            {{ $data['hours_ar'] ?? 'ساعتان تدريبيتان · عن بُعد' }}
                            <svg class="date-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="8" cy="8" r="6.5" stroke="{{ $primary }}" stroke-width="1.2"/>
                                <line x1="8" y1="4.5" x2="8" y2="8.5"   stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                                <line x1="8" y1="8.5" x2="10.5" y2="10" stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span class="date-pipe"></span>
                        <span class="date-item date-item-ar">
                            {{ $data['date_ar'] ?? 'السبت ٢٠ يونيو ٢٠٢٦' }}
                            <svg class="date-icon" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="1" y="3" width="14" height="12" rx="1.5" stroke="{{ $primary }}" stroke-width="1.2"/>
                                <line x1="1" y1="6.5" x2="15" y2="6.5" stroke="{{ $primary }}" stroke-width="1"/>
                                <line x1="5"  y1="1.5" x2="5"  y2="4.5" stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                                <line x1="11" y1="1.5" x2="11" y2="4.5" stroke="{{ $primary }}" stroke-width="1.2" stroke-linecap="round"/>
                            </svg>
                        </span>
                    </div>
                </div>
            </div>
            <div class="date-divider-line"></div>
        </div>

        {{-- ── SPACER ──────────────────────────────────────────────────────────── --}}
        <div class="cert-spacer"></div>

        {{-- ── DESCRIPTION ────────────────────────────────────────────────────── --}}
        <div class="description-table">
            <div class="description-row">
                <div class="desc-en-cell">
                    <p>{!! $descEn !!}</p>
                </div>
                <div class="desc-ar-cell">
                    <p>{!! $descAr !!}</p>
                </div>
            </div>
        </div>

        {{-- ── FOOTER: Signatures + Seal ──────────────────────────────────────── --}}
        @include('certificates.components.signatures')

    </div>{{-- .cert-content --}}
</div>{{-- .cert-page --}}
</body>
</html>
