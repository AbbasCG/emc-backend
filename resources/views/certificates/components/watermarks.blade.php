{{--
    Background Watermarks & Decorative SVG Layer
    Variables: $cfg (template config array)
--}}
@php
    $primary   = $cfg['color_primary']   ?? '#0D47A1';
    $accent    = $cfg['color_accent']    ?? '#29B6F6';
    $secondary = $cfg['color_secondary'] ?? '#F57C00';
    $bgStyle   = $cfg['bg_style']        ?? 'default';
@endphp

<svg class="bg-decoration" viewBox="0 0 1122 794" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">

  @if($bgStyle !== 'none')
  {{-- ── Top-left decorative arcs ── --}}
  <circle cx="-65" cy="-55" r="320" stroke="{{ $accent }}" stroke-width="1.2" opacity="0.12"/>
  <circle cx="-65" cy="-55" r="265" stroke="{{ $accent }}" stroke-width="1"   opacity="0.09"/>
  <circle cx="-65" cy="-55" r="210" stroke="{{ $accent }}" stroke-width="0.8" opacity="0.07"/>
  <circle cx="-65" cy="-55" r="155" stroke="{{ $accent }}" stroke-width="0.6" opacity="0.05"/>

  {{-- ── Bottom-right decorative arcs ── --}}
  <circle cx="1187" cy="849" r="320" stroke="{{ $accent }}" stroke-width="1.2" opacity="0.12"/>
  <circle cx="1187" cy="849" r="265" stroke="{{ $accent }}" stroke-width="1"   opacity="0.09"/>
  <circle cx="1187" cy="849" r="210" stroke="{{ $accent }}" stroke-width="0.8" opacity="0.07"/>
  <circle cx="1187" cy="849" r="155" stroke="{{ $accent }}" stroke-width="0.6" opacity="0.05"/>

  {{-- ── Center watermark circle (EMC identity ring) ── --}}
  <circle cx="561" cy="397" r="235" stroke="{{ $primary }}" stroke-width="0.5" opacity="0.04"/>
  <circle cx="561" cy="397" r="215" stroke="{{ $primary }}" stroke-width="0.5" opacity="0.035"/>
  <circle cx="561" cy="397" r="195" stroke="{{ $primary }}" stroke-width="0.4" opacity="0.03"/>

  {{-- ── Subtle corner accent dots ── --}}
  <circle cx="45"   cy="45"  r="5" fill="{{ $accent }}"    opacity="0.1"/>
  <circle cx="1077" cy="45"  r="5" fill="{{ $primary }}"   opacity="0.1"/>
  <circle cx="45"   cy="749" r="5" fill="{{ $primary }}"   opacity="0.1"/>
  <circle cx="1077" cy="749" r="5" fill="{{ $secondary }}" opacity="0.08"/>

  {{-- ── Top edge thin accent line ── --}}
  <line x1="0" y1="1" x2="1122" y2="1" stroke="{{ $primary }}" stroke-width="2" opacity="0.06"/>
  {{-- ── Bottom edge thin accent line ── --}}
  <line x1="0" y1="793" x2="1122" y2="793" stroke="{{ $primary }}" stroke-width="2" opacity="0.06"/>
  @endif

  @if($bgStyle === 'ornate')
  {{-- ── Additional ornamental arcs for 'ornate' style ── --}}
  <circle cx="-65"  cy="-55"  r="370" stroke="{{ $primary }}" stroke-width="0.6" opacity="0.04"/>
  <circle cx="1187" cy="849"  r="370" stroke="{{ $primary }}" stroke-width="0.6" opacity="0.04"/>
  {{-- Diagonal crosshatch guide lines (very faint) --}}
  <line x1="0"    y1="0"   x2="1122" y2="794"  stroke="{{ $accent }}" stroke-width="0.3" opacity="0.03"/>
  <line x1="1122" y1="0"   x2="0"    y2="794"  stroke="{{ $accent }}" stroke-width="0.3" opacity="0.03"/>
  @endif

</svg>

{{-- Border frame (optional) --}}
@if(($cfg['border_style'] ?? 'default') !== 'none')
<div style="
    position: absolute;
    top: 4mm; left: 4mm; right: 4mm; bottom: 4mm;
    border: 0.4mm solid {{ $primary }}22;
    border-radius: 2mm;
    pointer-events: none;
    z-index: 0;
"></div>
@endif
