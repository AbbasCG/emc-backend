{{--
    Signatures Component
    Renders the three-column footer: admin sig | official seal | programs sig
    Variables: $cfg, $data
--}}
@php
    $primary   = $cfg['color_primary']   ?? '#0D47A1';
    $secondary = $cfg['color_secondary'] ?? '#F57C00';
    $fontEn    = $cfg['font_english']    ?? 'Poppins';
    $fontAr    = $cfg['font_arabic']     ?? 'Cairo';

    $showAdmin   = $cfg['show_admin_signature']   ?? true;
    $showProgram = $cfg['show_program_signature'] ?? true;
    $showStamp   = $cfg['show_official_stamp']    ?? true;

    $adminSigImg    = $cfg['admin_sig_image']     ?? null;
    $adminNameEn    = $cfg['admin_sig_name_en']   ?? 'Tareq Tuaayman';
    $adminNameAr    = $cfg['admin_sig_name_ar']   ?? 'طارق طعيمان';
    $adminTitleEn   = $cfg['admin_sig_title_en']  ?? 'Founder & General Manager – EMC';
    $adminTitleAr   = $cfg['admin_sig_title_ar']  ?? 'مؤسس ومدير عام – EMC';

    $programSigImg  = $cfg['program_sig_image']   ?? null;
    $programNameEn  = $cfg['program_sig_name_en'] ?? null;
    $programNameAr  = $cfg['program_sig_name_ar'] ?? 'إدارة المعسكر الصيفي للذكاء الاصطناعي';
    $programTitleEn = $cfg['program_sig_title_en']?? null;
    $programTitleAr = $cfg['program_sig_title_ar']?? 'مركز ماستر التعليمي – EMC';

    $stampImg      = $cfg['official_stamp_image'] ?? null;
    $certNumber    = $data['certificate_number']  ?? 'EMC-WS1-2026-XXXX';
@endphp

<div class="cert-footer">
    <div class="cert-footer-row">

        {{-- LEFT: Administrative Signature --}}
        <div class="sig-left-cell">
            @if($showAdmin)
            <div class="sig-block">
                {{-- Circular signature frame --}}
                <div class="sig-circle-frame">
                    @if($adminSigImg)
                    <img src="{{ $adminSigImg }}" alt="{{ $adminNameEn }}">
                    @else
                    <div class="sig-placeholder">
                        <svg width="35" height="35" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M5 30 Q10 15 20 20 Q30 25 35 10" stroke="{{ $primary }}44" stroke-width="2" fill="none" stroke-linecap="round"/>
                        </svg>
                    </div>
                    @endif
                </div>
                <div class="sig-line"></div>
                <div class="sig-name-en">{{ $adminNameEn }}</div>
                @if($adminNameAr)
                <div class="sig-name-ar">{{ $adminNameAr }}</div>
                @endif
                @if($adminTitleEn)
                <div class="sig-title-en">{{ $adminTitleEn }}</div>
                @endif
            </div>
            @endif
        </div>

        {{-- CENTER: Official Seal + Certificate Number --}}
        <div class="seal-cell">
            @if($showStamp)
            <div class="seal-block">
                <div class="seal-circle">
                    @if($stampImg)
                    <img src="{{ $stampImg }}" alt="Official Seal">
                    @else
                    {{-- Default EMC seal placeholder (SVG) --}}
                    <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg" width="80%" height="80%">
                        <circle cx="40" cy="40" r="36" stroke="{{ $secondary }}" stroke-width="2" fill="none" opacity="0.5"/>
                        <circle cx="40" cy="40" r="28" stroke="{{ $secondary }}" stroke-width="1" fill="none" opacity="0.3"/>
                        <text x="40" y="35" text-anchor="middle" font-size="12" font-weight="800" fill="{{ $secondary }}" font-family="Arial, sans-serif">EMC</text>
                        <text x="40" y="50" text-anchor="middle" font-size="5" fill="{{ $secondary }}88" font-family="Arial, sans-serif">EDUCATIONAL</text>
                        <text x="40" y="58" text-anchor="middle" font-size="5" fill="{{ $secondary }}88" font-family="Arial, sans-serif">MASTAR CENTRAL</text>
                    </svg>
                    @endif
                </div>
                <div class="cert-number">{{ $certNumber }}</div>
            </div>
            @else
            {{-- Just show cert number if stamp hidden --}}
            <div style="text-align: center; padding-top: 10mm;">
                <div class="cert-number">{{ $certNumber }}</div>
            </div>
            @endif
        </div>

        {{-- RIGHT: Programs Department Signature --}}
        <div class="sig-right-cell">
            @if($showProgram)
            <div class="sig-block">
                <div class="sig-circle-frame">
                    @if($programSigImg)
                    <img src="{{ $programSigImg }}" alt="{{ $programNameAr }}">
                    @else
                    <div class="sig-placeholder">
                        <svg width="35" height="35" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M5 30 Q10 15 20 20 Q30 25 35 10" stroke="{{ $primary }}44" stroke-width="2" fill="none" stroke-linecap="round"/>
                        </svg>
                    </div>
                    @endif
                </div>
                <div class="sig-line"></div>
                @if($programNameAr)
                <div class="sig-name-ar">{{ $programNameAr }}</div>
                @endif
                @if($programNameEn)
                <div class="sig-name-en">{{ $programNameEn }}</div>
                @endif
                @if($programTitleAr)
                <div class="sig-title-ar">{{ $programTitleAr }}</div>
                @endif
            </div>
            @endif
        </div>

    </div>
</div>
