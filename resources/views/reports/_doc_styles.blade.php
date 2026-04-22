{{--
    Shared CSS engine for document templates.
    Call from any PDF blade like: @include('reports._doc_styles', ['config' => $config])
    Only emits <style>. Caller renders actual content.
--}}
@php
    $c = array_merge(\App\Models\DocumentTemplate::DEFAULT_CONFIG, $config ?? []);
    $tableStyle = $c['table_style'] ?? 'clean';
@endphp
<style>
    @page {
        margin: {{ $c['page_margin_top'] }}px {{ $c['page_margin_right'] }}px {{ $c['page_margin_bottom'] }}px {{ $c['page_margin_left'] }}px;
    }
    body {
        font-family: "{{ $c['font_family'] }}", sans-serif;
        font-size: {{ $c['font_size_body'] }}pt;
        line-height: 1.55;
        color: {{ $c['primary_color'] }};
    }
    h1 { font-size: 16pt; margin: 0 0 6px; color: {{ $c['primary_color'] }}; }
    h2 { font-size: 13pt; margin: 18px 0 6px; color: {{ $c['accent_color'] }}; border-bottom: 1px solid {{ $c['accent_color'] }}22; padding-bottom: 3px; }
    h3 { font-size: 11pt; margin: 14px 0 4px; color: {{ $c['primary_color'] }}; }
    .muted { color: #94a3b8; font-size: 9pt; }
    .accent { color: {{ $c['accent_color'] }}; }

    @if($c['header_enabled'] ?? true)
    .header {
        position: fixed; top: -{{ max(40, $c['page_margin_top'] - 40) }}px; left: 0; right: 0;
        padding: 8px 0;
        @if($c['header_bg'])background: {{ $c['header_bg'] }};@endif
        @if($c['header_border_bottom'] ?? true)border-bottom: 1px solid {{ $c['accent_color'] }}44;@endif
        display: table; width: 100%;
    }
    .header-left { display: table-cell; vertical-align: middle; }
    .header-right { display: table-cell; vertical-align: middle; text-align: right; font-size: 9pt; color: #64748b; }
    .header img { max-height: 42px; }
    @endif

    @if($c['footer_enabled'] ?? true)
    .footer {
        position: fixed; bottom: -{{ max(30, $c['page_margin_bottom'] - 40) }}px; left: 0; right: 0;
        padding-top: 6px; border-top: 1px solid #e2e8f0;
        font-size: 8pt; color: #94a3b8;
        display: table; width: 100%;
    }
    .footer-left { display: table-cell; }
    .footer-right { display: table-cell; text-align: right; }
    @endif

    @if($c['watermark_enabled'] ?? false)
    .watermark {
        position: fixed; top: 40%; left: 0; right: 0;
        text-align: center;
        font-size: 90pt;
        color: rgba(100, 116, 139, {{ $c['watermark_opacity'] ?? 0.08 }});
        font-weight: 900; letter-spacing: 8px;
        transform: rotate({{ $c['watermark_rotate'] ?? -25 }}deg);
        z-index: -1;
    }
    @endif

    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    table.meta td { padding: 4px 8px; vertical-align: top; }
    table.meta td:first-child { width: 40%; font-weight: 700; color: #475569; }

    /* Table style variants */
    @if($tableStyle === 'clean')
        table.grid th, table.grid td { padding: 6px 10px; text-align: left; font-size: 10pt; border-bottom: 1px solid #e2e8f0; }
        table.grid th { background: transparent; font-weight: 700; color: {{ $c['accent_color'] }}; border-bottom-width: 2px; }
    @elseif($tableStyle === 'striped')
        table.grid th, table.grid td { padding: 6px 10px; text-align: left; font-size: 10pt; }
        table.grid th { background: {{ $c['accent_color'] }}15; color: {{ $c['accent_color'] }}; font-weight: 700; }
        table.grid tr:nth-child(even) td { background: #f8fafc; }
    @elseif($tableStyle === 'bordered')
        table.grid th, table.grid td { border: 1px solid #cbd5e1; padding: 6px 10px; text-align: left; font-size: 10pt; }
        table.grid th { background: #f1f5f9; font-weight: 700; color: {{ $c['primary_color'] }}; }
    @elseif($tableStyle === 'rounded')
        table.grid { border: 1px solid {{ $c['accent_color'] }}44; border-radius: 8px; overflow: hidden; }
        table.grid th, table.grid td { padding: 8px 12px; text-align: left; font-size: 10pt; border-bottom: 1px solid {{ $c['accent_color'] }}22; }
        table.grid th { background: {{ $c['accent_color'] }}18; color: {{ $c['accent_color'] }}; font-weight: 700; }
        table.grid tr:last-child td { border-bottom: none; }
    @elseif($tableStyle === 'minimal')
        table.grid th, table.grid td { padding: 6px 10px; text-align: left; font-size: 10pt; }
        table.grid th { font-weight: 700; color: {{ $c['accent_color'] }}; padding-bottom: 4px; }
    @elseif($tableStyle === 'modern')
        table.grid { border-left: 3px solid {{ $c['accent_color'] }}; }
        table.grid th, table.grid td { padding: 8px 12px; text-align: left; font-size: 10pt; }
        table.grid th { background: {{ $c['primary_color'] }}; color: #fff; font-weight: 700; }
        table.grid tr:nth-child(odd) td { background: {{ $c['accent_color'] }}08; }
    @endif

    .badge {
        display: inline-block; padding: 2px 8px; border-radius: 999px;
        font-size: 9pt; font-weight: 700; letter-spacing: 0.3px;
    }
    .b-red { background: #fee2e2; color: #991b1b; }
    .b-amber { background: #fef3c7; color: #92400e; }
    .b-blue { background: {{ $c['accent_color'] }}22; color: {{ $c['accent_color'] }}; }
    .b-green { background: #dcfce7; color: #166534; }
    .sig-block { margin-top: 50px; width: 45%; }
    .sig-line { border-bottom: 1px solid {{ $c['primary_color'] }}; height: 60px; }
    .callout {
        padding: 10px 14px; border-left: 4px solid {{ $c['accent_color'] }};
        background: {{ $c['accent_color'] }}10; margin: 12px 0; font-size: 10pt;
    }
    .callout.warn { border-color: #f59e0b; background: #fffbeb; }
    .callout.info { border-color: #0ea5e9; background: #f0f9ff; }
    ul, ol { padding-left: 20px; }
    li { margin: 3px 0; }
</style>
