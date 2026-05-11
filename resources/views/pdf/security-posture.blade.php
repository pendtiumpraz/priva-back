<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Security Posture Report — {{ $data['platform'] }}</title>
<style>
    @page { margin: 50px 40px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; color: #1e293b; line-height: 1.5; }
    h1 { font-size: 20pt; margin: 0 0 4pt; color: #0f172a; }
    h2 { font-size: 13pt; margin: 22pt 0 6pt; color: #1e40af; border-bottom: 1.5pt solid #1e40af; padding-bottom: 3pt; }
    h3 { font-size: 11pt; margin: 14pt 0 4pt; color: #334155; }
    p { margin: 4pt 0; }
    .meta-box { background: #f1f5f9; border: 1pt solid #cbd5e1; border-radius: 4pt; padding: 10pt 14pt; margin: 12pt 0; }
    .meta-row { display: block; font-size: 9pt; line-height: 1.7; }
    .meta-row strong { color: #0f172a; }
    .summary-grid { width: 100%; margin: 10pt 0; }
    .summary-grid td { padding: 8pt 12pt; border: 1pt solid #cbd5e1; text-align: center; vertical-align: middle; }
    .summary-grid .label { font-size: 8pt; color: #64748b; text-transform: uppercase; }
    .summary-grid .value { font-size: 18pt; font-weight: bold; }
    .summary-grid .ok { color: #16a34a; }
    .summary-grid .warn { color: #ea580c; }
    .summary-grid .neutral { color: #475569; }
    table.posture { width: 100%; border-collapse: collapse; margin: 6pt 0 12pt; font-size: 9pt; }
    table.posture th { background: #1e40af; color: #fff; padding: 6pt 8pt; text-align: left; font-weight: normal; }
    table.posture td { border: 0.5pt solid #cbd5e1; padding: 5pt 8pt; vertical-align: top; }
    table.posture tr:nth-child(even) td { background: #f8fafc; }
    .badge { display: inline-block; padding: 2pt 6pt; border-radius: 8pt; font-size: 8pt; font-weight: bold; }
    .badge-on { background: #dcfce7; color: #166534; }
    .badge-off { background: #fef3c7; color: #92400e; }
    .badge-config { background: #dbeafe; color: #1e40af; }
    .badge-info { background: #e2e8f0; color: #475569; }
    .group-desc { font-size: 9pt; color: #64748b; margin: 0 0 6pt; font-style: italic; }
    .footer { margin-top: 20pt; padding-top: 10pt; border-top: 0.5pt solid #cbd5e1; font-size: 8pt; color: #94a3b8; text-align: center; }
    .notes { background: #f0f9ff; border-left: 3pt solid #0284c7; padding: 10pt 14pt; margin: 12pt 0; font-size: 9pt; }
    .notes ol { margin: 0; padding-left: 18pt; }
    .notes li { margin: 4pt 0; }
</style>
</head>
<body>

<h1>Security Posture Report</h1>
<p style="color: #64748b; font-size: 11pt;">
    Ringkasan status implementasi keamanan platform {{ $data['platform'] }}
</p>

<div class="meta-box">
    <span class="meta-row"><strong>Platform:</strong> {{ $data['platform'] }} ({{ $data['platform_url'] }})</span>
    <span class="meta-row"><strong>Deployment Mode:</strong> {{ strtoupper($data['deployment_mode']) }}</span>
    <span class="meta-row"><strong>Generated:</strong> {{ $data['generated_at_human'] }}</span>
    <span class="meta-row"><strong>Document Purpose:</strong> Annex untuk Penetration Test Report. Berisi
        ringkasan kontrol keamanan yang sudah di-implement, default value, dan status aktif/nonaktif per setting.</span>
</div>

<h2>Ringkasan</h2>

<table class="summary-grid">
    <tr>
        <td><div class="label">Total Setting</div><div class="value neutral">{{ $data['summary']['total_settings'] }}</div></td>
        <td><div class="label">Enabled / Configured</div><div class="value ok">{{ $data['summary']['enabled'] }}</div></td>
        <td><div class="label">Disabled (opt-in)</div><div class="value warn">{{ $data['summary']['disabled'] }}</div></td>
    </tr>
</table>

<div class="notes">
    <strong>Catatan Implementasi:</strong>
    <ol>
        @foreach ($data['implementation_notes'] as $note)
            <li>{{ $note }}</li>
        @endforeach
    </ol>
</div>

@foreach ($data['groups'] as $group)
    <h3>{{ $group['name'] }}
        @php
            $ms = $group['master_status'] ?? 'configured';
            $msClass = $ms === 'enabled' ? 'badge-on' : ($ms === 'disabled' ? 'badge-off' : 'badge-config');
        @endphp
        <span class="badge {{ $msClass }}" style="margin-left: 8pt; vertical-align: 2pt;">{{ strtoupper($ms) }}</span>
    </h3>
    <p class="group-desc">{{ $group['description'] }}</p>
    <table class="posture">
        <thead>
            <tr>
                <th style="width: 40%;">Setting</th>
                <th style="width: 25%;">Nilai Aktif</th>
                <th style="width: 20%;">Default</th>
                <th style="width: 15%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($group['items'] as $item)
                @php
                    $s = $item['status'] ?? 'default';
                    $badgeClass = match($s) {
                        'enabled' => 'badge-on',
                        'disabled' => 'badge-off',
                        'configured' => 'badge-config',
                        default => 'badge-info',
                    };
                @endphp
                <tr>
                    <td><strong>{{ $item['label'] }}</strong><br>
                        <span style="font-size: 8pt; color: #94a3b8;">{{ $item['key'] }}</span></td>
                    <td>{{ is_array($item['value']) ? json_encode($item['value']) : (string) $item['value'] }}</td>
                    <td><span style="color: #94a3b8;">{{ is_array($item['default']) ? json_encode($item['default']) : (string) $item['default'] }}</span></td>
                    <td><span class="badge {{ $badgeClass }}">{{ strtoupper($s) }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach

<div class="footer">
    Generated by {{ $data['platform'] }} Security Posture Service · {{ $data['generated_at_human'] }} ·
    Dokumen ini dihasilkan otomatis dari status setting aktual di system_settings table.
    Untuk validasi independen, silakan jalankan penetration test oleh vendor pihak ketiga.
</div>

</body>
</html>
