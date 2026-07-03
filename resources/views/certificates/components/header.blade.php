{{--
    Certificate Header Component
    Variables: $cfg (template config), $data (certificate data)
--}}
@php
    $primary   = $cfg['color_primary']   ?? '#0D47A1';
    $accent    = $cfg['color_accent']    ?? '#29B6F6';
    $fontEn    = $cfg['font_english']    ?? 'Poppins';
    $fontAr    = $cfg['font_arabic']     ?? 'Cairo';
    $orgNameEn = $cfg['org_name_en']     ?? 'Educational Mastar Central';
    $orgNameAr = $cfg['org_name_ar']     ?? 'مركز ماستر التعليمي';
    $logoSrc   = $cfg['logo_primary']    ?? null;

    $badgeEn = $data['camp_badge_en'] ?? '';
    $badgeAr = $data['camp_badge_ar'] ?? '';

    // Split org name for stacked display
    $orgParts = array_filter(explode(' ', $orgNameEn));
@endphp

<div class="cert-header">
    <div class="cert-header-row">

        {{-- LEFT: English badge --}}
        <div class="header-left">
            @if($badgeEn)
            <span class="cert-badge badge-en">{{ $badgeEn }}</span>
            @endif
        </div>

        {{-- CENTER: Logo(s) + org names --}}
        <div class="header-center">
            <div class="logos-wrap">
                {{-- Organization 1 text --}}
                <div class="org-text-block">
                    @php $words = array_values(array_filter(explode(' ', $orgNameEn))); @endphp
                    @if(count($words) >= 3)
                        <div class="org-line-1">{{ $words[0] }}</div>
                        <div class="org-line-2">{{ $words[1] }}</div>
                        <div class="org-line-3">{{ implode(' ', array_slice($words, 2)) }}</div>
                    @else
                        <div class="org-line-2">{{ $orgNameEn }}</div>
                    @endif
                </div>

                {{-- Primary logo (EMC eagle) --}}
                @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="{{ $orgNameEn }}" class="logo-img">
                @else
                {{-- Placeholder logo when not uploaded --}}
                <div style="
                    width: 18mm; height: 18mm;
                    border-radius: 50%;
                    background: linear-gradient(135deg, {{ $accent }}33, {{ $primary }}33);
                    border: 0.5mm solid {{ $primary }}44;
                    display: flex; align-items: center; justify-content: center;
                ">
                    <span style="font-size: 4mm; font-weight: 800; color: {{ $primary }}; font-family: '{{ $fontEn }}', sans-serif;">EMC</span>
                </div>
                @endif

                <div class="logo-divider"></div>

                {{-- Organization Arabic name --}}
                <div style="text-align: right;">
                    <div class="org-ar-text">{{ $orgNameAr }}</div>
                    @if(!empty($cfg['org2_name_ar']))
                    <div class="org-ar-sub">{{ $cfg['org2_name_ar'] }}</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- RIGHT: Arabic badge --}}
        <div class="header-right">
            @if($badgeAr)
            <span class="cert-badge badge-ar">{{ $badgeAr }}</span>
            @endif
        </div>

    </div>
</div>
