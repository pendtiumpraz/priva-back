@php
    $cfg = $config ?? \App\Models\DocumentTemplate::DEFAULT_CONFIG;
    $primary = $cfg['primary_color'] ?? '#1e293b';
    $accent = $cfg['accent_color'] ?? '#3b82f6';
@endphp
{{-- Self-contained, scoped HTML snippet for embedding a generated policy into a
     tenant's own website. All styles are namespaced under .pg-policy so they do
     not leak into the host page. --}}
<section class="pg-policy">
    <style>
        .pg-policy { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111827; line-height: 1.6; max-width: 820px; margin: 0 auto; }
        .pg-policy .pg-head { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid {{ $accent }}; padding-bottom: 10px; margin-bottom: 18px; }
        .pg-policy .pg-head img { max-height: 40px; max-width: 160px; }
        .pg-policy .pg-head .org { font-weight: 700; color: {{ $primary }}; font-size: 1.05rem; }
        .pg-policy .pg-doc-title { font-size: 1.6rem; font-weight: 800; color: {{ $primary }}; margin: 0 0 4px; }
        .pg-policy .pg-meta { color: #6b7280; font-size: 0.85rem; font-style: italic; margin-bottom: 20px; }
        .pg-policy h1 { font-size: 1.25rem; color: {{ $primary }}; border-bottom: 1px solid {{ $accent }}; padding-bottom: 3px; margin: 22px 0 10px; }
        .pg-policy h2 { font-size: 1.05rem; color: #1f2937; margin: 16px 0 6px; }
        .pg-policy h3 { font-size: 0.98rem; color: #374151; margin: 12px 0 4px; }
        .pg-policy p { text-align: justify; margin: 0 0 12px; }
        .pg-policy p.pg-disclaimer { margin-top: 20px; padding: 10px 12px; background: #fef3c7; border-left: 4px solid #d97706; font-size: 0.85rem; color: #92400e; }
        .pg-policy ul { margin: 0 0 12px 20px; } .pg-policy li { margin: 0 0 4px; }
        .pg-policy table.doc-table { border-collapse: collapse; width: 100%; margin: 8px 0 16px; font-size: 0.9rem; }
        .pg-policy table.doc-table th { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        .pg-policy table.doc-table td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
        .pg-policy .pg-foot { border-top: 1px solid #e5e7eb; margin-top: 24px; padding-top: 8px; color: #6b7280; font-size: 0.8rem; }
    </style>

    <div class="pg-head">
        @if(($cfg['header_show_logo'] ?? true) && !empty($orgLogoUrl))
            <img src="{{ $orgLogoUrl }}" alt="logo">
        @endif
        @if(($cfg['header_show_org_name'] ?? true) && !empty($orgName))
            <span class="org">{{ $orgName }}</span>
        @endif
    </div>

    <div class="pg-doc-title">{{ $title }}</div>
    @php
        $metaParts = [];
        if (!empty($metadata['version'])) $metaParts[] = 'Versi '.$metadata['version'];
        if (!empty($metadata['language'])) $metaParts[] = 'Bahasa: '.strtoupper($metadata['language']);
    @endphp
    @if(count($metaParts))
        <div class="pg-meta">{{ implode('  ·  ', $metaParts) }}</div>
    @endif

    @include('reports.policy_generator._sections', ['sections' => $sections])

    <div class="pg-foot">
        {{ $orgName }}@if(!empty($orgWebsite)) · <a href="{{ $orgWebsite }}">{{ $orgWebsite }}</a>@endif
    </div>
</section>
