{{--
    Certificate Styles — A4 Landscape, bilingual, DomPDF-compatible
    $cfg['for_pdf'] = true  → local @font-face (PDF render)
    $cfg['for_pdf'] = false → Google Fonts CDN (browser preview in iframe)
--}}
@php
    $primary   = $cfg['color_primary']   ?? '#0D2741';
    $secondary = $cfg['color_secondary'] ?? '#F57C00';
    $accent    = $cfg['color_accent']    ?? '#009DDB';
    $textColor = $cfg['color_text']      ?? '#1A1A1A';
    $fontEn    = $cfg['font_english']    ?? 'Poppins';
    $fontAr    = $cfg['font_arabic']     ?? 'Cairo';
    $forPdf    = $cfg['for_pdf']         ?? false;

    $mt = ($cfg['margin_top']    ?? 7)  . 'mm';
    $mb = ($cfg['margin_bottom'] ?? 6)  . 'mm';
    $ml = ($cfg['margin_left']   ?? 10) . 'mm';
    $mr = ($cfg['margin_right']  ?? 10) . 'mm';
@endphp

@if(! $forPdf)
{{-- Browser preview: Google Fonts CDN --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800;900&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,600&display=swap" rel="stylesheet">
@else
{{-- PDF render: local TTF files --}}
<style>
@font-face { font-family:'Poppins'; src:url('{{ storage_path("fonts/Poppins-Regular.ttf") }}');    font-weight:400; font-style:normal; }
@font-face { font-family:'Poppins'; src:url('{{ storage_path("fonts/Poppins-Italic.ttf") }}');     font-weight:400; font-style:italic; }
@font-face { font-family:'Poppins'; src:url('{{ storage_path("fonts/Poppins-SemiBold.ttf") }}');   font-weight:600; font-style:normal; }
@font-face { font-family:'Poppins'; src:url('{{ storage_path("fonts/Poppins-Bold.ttf") }}');       font-weight:700; font-style:normal; }
@font-face { font-family:'Poppins'; src:url('{{ storage_path("fonts/Poppins-ExtraBold.ttf") }}');  font-weight:800; font-style:normal; }
@font-face { font-family:'Cairo';   src:url('{{ storage_path("fonts/Cairo-Regular.ttf") }}');      font-weight:400; font-style:normal; }
@font-face { font-family:'Cairo';   src:url('{{ storage_path("fonts/Cairo-SemiBold.ttf") }}');     font-weight:600; font-style:normal; }
@font-face { font-family:'Cairo';   src:url('{{ storage_path("fonts/Cairo-Bold.ttf") }}');         font-weight:700; font-style:normal; }
@font-face { font-family:'Cairo';   src:url('{{ storage_path("fonts/Cairo-ExtraBold.ttf") }}');    font-weight:800; font-style:normal; }
</style>
@endif

<style>
/* ═══════════════════════════════════════════════════════
   RESET
═══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ═══════════════════════════════════════════════════════
   PAGE — A4 Landscape 297 × 210 mm
═══════════════════════════════════════════════════════ */
@page { size: 297mm 210mm landscape; margin: 0; }

html, body {
    width: 297mm;
    height: 210mm;
    margin: 0;
    padding: 0;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ═══════════════════════════════════════════════════════
   OUTER WRAPPER
═══════════════════════════════════════════════════════ */
.cert-page {
    position: relative;
    width: 297mm;
    height: 210mm;
    background: #ffffff;
    overflow: hidden;
    padding: {{ $mt }} {{ $mr }} {{ $mb }} {{ $ml }};
    font-family: '{{ $fontEn }}', '{{ $fontAr }}', Arial, sans-serif;
    color: {{ $textColor }};
}

/* ═══════════════════════════════════════════════════════
   BACKGROUND SVG LAYER
═══════════════════════════════════════════════════════ */
.bg-decoration {
    position: absolute;
    top: 0; left: 0;
    width: 297mm; height: 210mm;
    pointer-events: none;
    z-index: 0;
}

/* ═══════════════════════════════════════════════════════
   CONTENT LAYER (flex column, fills inner area)
═══════════════════════════════════════════════════════ */
.cert-content {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    gap: 0;
}

/* ═══════════════════════════════════════════════════════
   HEADER: BADGES + LOGOS  (~20mm)
═══════════════════════════════════════════════════════ */
.cert-header {
    display: table;
    width: 100%;
    table-layout: fixed;
    flex-shrink: 0;
    margin-bottom: 2mm;
}
.cert-header-row { display: table-row; }
.header-left, .header-center, .header-right {
    display: table-cell;
    vertical-align: middle;
}
.header-left   { width: 24%; text-align: left;   vertical-align: middle; }
.header-right  { width: 24%; text-align: right;  vertical-align: middle; }
.header-center { width: 52%; text-align: center; padding: 0 3mm; }

/* Badges */
.cert-badge {
    display: inline-block;
    padding: 1.8mm 4.5mm;
    border-radius: 20mm;
    font-size: 2.8mm;
    font-weight: 700;
    letter-spacing: 0.08mm;
    line-height: 1.35;
    white-space: nowrap;
}
.badge-en {
    background-color: {{ $accent }};
    color: #fff;
    font-family: '{{ $fontEn }}', sans-serif;
}
.badge-ar {
    background-color: {{ $primary }};
    color: #fff;
    font-family: '{{ $fontAr }}', sans-serif;
    direction: rtl;
}

/* Center logos */
.logos-wrap {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 2.5mm;
}
.org-text-block { text-align: center; line-height: 1.2; }
.org-line-1 {
    font-size: 2.3mm; font-weight: 300;
    color: #666; font-family: '{{ $fontEn }}', sans-serif;
    letter-spacing: 0.6mm; text-transform: uppercase;
}
.org-line-2 {
    font-size: 4mm; font-weight: 800;
    color: {{ $primary }}; font-family: '{{ $fontEn }}', sans-serif;
}
.org-line-3 {
    font-size: 2.3mm; font-weight: 300;
    color: #666; font-family: '{{ $fontEn }}', sans-serif;
    letter-spacing: 0.6mm; text-transform: uppercase;
}
.logo-img { height: 16mm; width: auto; object-fit: contain; }
.logo-divider {
    width: 0.3mm; height: 13mm;
    background: {{ $primary }}33;
    display: inline-block;
    vertical-align: middle;
    margin: 0 2mm;
}
.org-ar-text {
    font-size: 4mm; font-weight: 800;
    color: {{ $primary }};
    font-family: '{{ $fontAr }}', sans-serif;
    direction: rtl; line-height: 1.35;
}
.org-ar-sub {
    font-size: 2.3mm; font-weight: 400;
    color: #888;
    font-family: '{{ $fontAr }}', sans-serif;
    direction: rtl;
}

/* ═══════════════════════════════════════════════════════
   PRESENTER BLOCK — stacked EN then AR, centered (~9mm)
═══════════════════════════════════════════════════════ */
.presenter-block {
    text-align: center;
    flex-shrink: 0;
    margin-bottom: 1.5mm;
}
.presenter-en {
    font-size: 2.5mm;
    font-weight: 500;
    font-family: '{{ $fontEn }}', sans-serif;
    color: #777;
    letter-spacing: 1.4mm;
    text-transform: uppercase;
    display: block;
    margin-bottom: 1mm;
}
.presenter-ar {
    font-size: 3mm;
    font-weight: 400;
    font-family: '{{ $fontAr }}', sans-serif;
    color: #666;
    direction: rtl;
    display: block;
}

/* ═══════════════════════════════════════════════════════
   PARTICIPANT NAME  (~14-16mm total)
═══════════════════════════════════════════════════════ */
.name-section {
    text-align: center;
    flex-shrink: 0;
    padding: 1.5mm 0 2mm;
    margin-bottom: 1.5mm;
}
.participant-name {
    font-size: 11mm;
    font-weight: 800;
    color: {{ $textColor }};
    font-family: '{{ $fontEn }}', '{{ $fontAr }}', sans-serif;
    line-height: 1.05;
    letter-spacing: -0.3mm;
}
.name-underline {
    display: block;
    width: 110mm;
    height: 0.7mm;
    background: linear-gradient(to right, transparent, {{ $primary }}, {{ $accent }}, {{ $primary }}, transparent);
    margin: 1.8mm auto 0;
    border-radius: 1mm;
}

/* ═══════════════════════════════════════════════════════
   ORANGE SEPARATOR  (~2.5mm)
═══════════════════════════════════════════════════════ */
.orange-separator {
    height: 1mm;
    background: linear-gradient(to right, transparent 5%, {{ $secondary }} 25%, {{ $secondary }} 75%, transparent 95%);
    flex-shrink: 0;
    margin: 0.8mm 0 2.5mm;
    border-radius: 1mm;
}

/* ═══════════════════════════════════════════════════════
   MAIN CONTENT — TWO COLUMNS  (~38-44mm)
═══════════════════════════════════════════════════════ */
.main-content-table {
    display: table;
    width: 100%;
    table-layout: fixed;
    flex-shrink: 0;
    margin-bottom: 1.5mm;
}
.main-content-row { display: table-row; }

.col-en-cell {
    display: table-cell;
    width: 47%;
    vertical-align: top;
    padding-right: 4mm;
}
.col-divider-cell {
    display: table-cell;
    width: 6%;
    vertical-align: middle;
    text-align: center;
}
.col-divider-line {
    display: inline-block;
    width: 0.3mm;
    height: 28mm;
    background: linear-gradient(to bottom, transparent, {{ $accent }}66, {{ $accent }}66, transparent);
    vertical-align: middle;
}
.col-ar-cell {
    display: table-cell;
    width: 47%;
    vertical-align: top;
    padding-left: 4mm;
    direction: rtl;
}

/* English column */
.cert-main-title-en {
    font-size: 8.5mm;
    font-weight: 900;
    color: {{ $primary }};
    font-family: '{{ $fontEn }}', sans-serif;
    letter-spacing: 2.5mm;
    text-transform: uppercase;
    line-height: 1;
    margin-bottom: 0.6mm;
}
.cert-sub-title-en {
    font-size: 3.5mm;
    font-weight: 400;
    color: {{ $accent }};
    font-family: '{{ $fontEn }}', sans-serif;
    letter-spacing: 2.8mm;
    text-transform: uppercase;
    margin-bottom: 3.5mm;
}
.cert-intro-en {
    font-size: 2.9mm;
    color: #666;
    font-family: '{{ $fontEn }}', sans-serif;
    line-height: 1.6;
    margin-bottom: 1.8mm;
    font-style: italic;
}
.course-title-en {
    font-size: 3.7mm;
    font-weight: 700;
    color: {{ $textColor }};
    font-family: '{{ $fontEn }}', sans-serif;
    line-height: 1.4;
}

/* Arabic column */
.cert-main-title-ar {
    font-size: 8.5mm;
    font-weight: 900;
    color: {{ $secondary }};
    font-family: '{{ $fontAr }}', sans-serif;
    line-height: 1;
    margin-bottom: 0.6mm;
    direction: rtl;
}
.cert-sub-title-ar {
    font-size: 3.8mm;
    font-weight: 600;
    color: {{ $textColor }};
    font-family: '{{ $fontAr }}', sans-serif;
    margin-bottom: 3.5mm;
    direction: rtl;
}
.cert-intro-ar {
    font-size: 2.9mm;
    color: #666;
    font-family: '{{ $fontAr }}', sans-serif;
    line-height: 1.65;
    margin-bottom: 1.8mm;
    direction: rtl;
}
.course-title-ar {
    font-size: 3.7mm;
    font-weight: 700;
    color: {{ $textColor }};
    font-family: '{{ $fontAr }}', sans-serif;
    line-height: 1.45;
    direction: rtl;
}

/* ═══════════════════════════════════════════════════════
   DATE / HOURS ROW  (~10mm)
═══════════════════════════════════════════════════════ */
.date-row-wrapper {
    flex-shrink: 0;
    margin-bottom: 2mm;
}
.date-divider-line {
    height: 0.2mm;
    background: #E2E8F0;
    margin: 1.2mm 0;
}
.date-row-table {
    display: table; width: 100%; table-layout: fixed;
}
.date-row-inner { display: table-row; }
.date-en-cell {
    display: table-cell; width: 50%;
    vertical-align: middle; padding: 1mm 0;
}
.date-ar-cell {
    display: table-cell; width: 50%;
    vertical-align: middle; text-align: right;
    direction: rtl; padding: 1mm 0;
}
.date-item {
    display: inline-flex; align-items: center;
    gap: 1.5mm; font-size: 3.1mm; color: #555;
}
.date-item-en { font-family: '{{ $fontEn }}', sans-serif; }
.date-item-ar { font-family: '{{ $fontAr }}', sans-serif; direction: rtl; }
.date-icon {
    width: 3.5mm; height: 3.5mm;
    display: inline-block; vertical-align: middle;
}
.date-pipe {
    display: inline-block;
    width: 0.2mm; height: 3.5mm;
    background: #CBD5E0;
    margin: 0 2.5mm;
    vertical-align: middle;
}

/* ═══════════════════════════════════════════════════════
   SPACER — flex remainder between date row and description
═══════════════════════════════════════════════════════ */
.cert-spacer {
    flex: 1;
    min-height: 1mm;
}

/* ═══════════════════════════════════════════════════════
   DESCRIPTION (~18-22mm)
═══════════════════════════════════════════════════════ */
.description-table {
    display: table; width: 100%; table-layout: fixed;
    flex-shrink: 0;
    margin-bottom: 2.5mm;
}
.description-row { display: table-row; }
.desc-en-cell {
    display: table-cell; width: 50%;
    vertical-align: top; padding-right: 5mm;
}
.desc-ar-cell {
    display: table-cell; width: 50%;
    vertical-align: top; padding-left: 5mm;
    direction: rtl;
}
.desc-en-cell p {
    font-family: '{{ $fontEn }}', sans-serif;
    font-size: 2.7mm; line-height: 1.75; color: #555; margin: 0;
}
.desc-ar-cell p {
    font-family: '{{ $fontAr }}', sans-serif;
    font-size: 2.7mm; line-height: 1.85; color: #555; margin: 0;
    direction: rtl; text-align: right;
}
.desc-en-cell strong, .desc-ar-cell strong {
    font-weight: 700; color: {{ $textColor }};
}

/* ═══════════════════════════════════════════════════════
   FOOTER — signatures, seal, cert number  (~32-36mm)
═══════════════════════════════════════════════════════ */
.cert-footer {
    display: table;
    width: 100%;
    table-layout: fixed;
    flex-shrink: 0;
}
.cert-footer-row { display: table-row; }
.sig-left-cell, .seal-cell, .sig-right-cell {
    display: table-cell;
    width: 33.33%;
    vertical-align: bottom;
    text-align: center;
}

/* Signature block */
.sig-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
}
.sig-circle-frame {
    width: 20mm; height: 20mm;
    border-radius: 50%;
    border: 0.35mm dashed {{ $primary }}55;
    display: flex;
    align-items: center; justify-content: center;
    overflow: hidden;
    margin: 0 auto 1.5mm;
}
.sig-circle-frame img {
    width: 100%; height: 100%;
    object-fit: cover; border-radius: 50%;
    opacity: 0.9;
}
.sig-placeholder {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    background: #F8FAFC;
}
.sig-line {
    width: 38mm; height: 0.3mm;
    background: {{ $primary }}33;
    margin: 1mm auto 1.5mm;
}
.sig-name-en {
    font-size: 3.3mm; font-weight: 700;
    color: {{ $textColor }};
    font-family: '{{ $fontEn }}', sans-serif;
    line-height: 1.3;
}
.sig-name-ar {
    font-size: 3.3mm; font-weight: 700;
    color: {{ $textColor }};
    font-family: '{{ $fontAr }}', sans-serif;
    direction: rtl; line-height: 1.3;
}
.sig-title-en {
    font-size: 2.5mm; font-weight: 400;
    color: #777;
    font-family: '{{ $fontEn }}', sans-serif;
    line-height: 1.3;
}
.sig-title-ar {
    font-size: 2.5mm; font-weight: 400;
    color: #777;
    font-family: '{{ $fontAr }}', sans-serif;
    direction: rtl; line-height: 1.3;
}

/* Official Seal */
.seal-block {
    display: flex; flex-direction: column;
    align-items: center; gap: 0;
}
.seal-circle {
    width: 24mm; height: 24mm;
    border-radius: 50%;
    border: 0.5mm solid {{ $secondary }}44;
    box-shadow: 0 0 0 1.2mm {{ $secondary }}15;
    background: #FFFAF5;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
    margin: 0 auto 1.5mm;
}
.seal-circle img {
    width: 88%; height: 88%;
    object-fit: contain;
}
.cert-number {
    font-size: 2.8mm; font-weight: 600;
    color: #666;
    font-family: 'Courier New', 'Courier', monospace;
    letter-spacing: 0.3mm;
    margin-top: 0.8mm;
}

/* ═══════════════════════════════════════════════════════
   PRINT
═══════════════════════════════════════════════════════ */
@media print {
    html, body { width: 297mm; height: 210mm; }
    .cert-page { page-break-after: avoid; }
}
</style>
