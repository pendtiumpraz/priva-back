@extends('reports.assessments._layout')
@section('title', 'Transfer Impact Assessment - ' . $tia->tia_code)
@section('doc-id', $tia->tia_code)

@php
    $verdictMap = [
        \App\Models\TiaAssessment::VERDICT_APPROVED => ['v-pass', 'AMAN — TRANSFER DISETUJUI'],
        \App\Models\TiaAssessment::VERDICT_CONDITIONAL => ['v-cond', 'BERSYARAT — DENGAN MITIGASI'],
        \App\Models\TiaAssessment::VERDICT_REJECTED => ['v-fail', 'DITOLAK — TIDAK AMAN'],
    ];
    [$verdictClass, $verdictLabel] = $verdictMap[$tia->conclusion_verdict] ?? ['v-pending', 'BELUM DIPUTUSKAN'];

    $riskMetrics = [
        'risk_regulation_mismatch' => 'Ketidakcocokan Regulasi',
        'risk_contractual_breach' => 'Pelanggaran Kontrak',
        'risk_admin_sanctions' => 'Sanksi Administratif',
        'risk_data_leak' => 'Kebocoran Data',
        'risk_data_integrity' => 'Integritas Data',
        'risk_sovereign_access' => 'Akses Otoritas Asing',
    ];
    $secMetrics = [
        'security_protocol_score' => 'Protokol Keamanan',
        'security_encryption_score' => 'Enkripsi Data',
    ];
    $riskColor = function ($score) {
        if ($score === null) return '#94a3b8';
        if ($score >= 7) return '#ef4444';
        if ($score >= 4) return '#f59e0b';
        return '#22c55e';
    };
    $secColor = function ($score) {
        if ($score === null) return '#94a3b8';
        if ($score >= 7) return '#22c55e';
        if ($score >= 4) return '#eab308';
        return '#ef4444';
    };
@endphp

@section('content')
<h1 class="doc-title">Transfer Impact Assessment</h1>
<p class="doc-sub">UU PDP No. 27 Tahun 2022 · Pasal 56 — Transfer Data Lintas Negara</p>

<div class="doc-frame">
    <div class="meta-row"><span class="lbl">Kode TIA</span>: <strong>{{ $tia->tia_code }}</strong></div>
    <div class="meta-row"><span class="lbl">Judul</span>: <strong>{{ $tia->title }}</strong></div>
    <div class="meta-row"><span class="lbl">Negara Tujuan</span>: <strong>{{ $tia->destination_country ?? '—' }}</strong>
        @if($tia->destination_has_pdp_law) <span class="badge b-green">UU PDP ✓</span> @endif
        @if($tia->destination_has_pdp_authority) <span class="badge b-green">Otoritas PDP ✓</span> @endif
    </div>
    @if($tia->crossBorder)
    <div class="meta-row"><span class="lbl">Cross-Border Record</span>: {{ $tia->crossBorder->destination_entity ?? $tia->crossBorder->destination_country }}</div>
    @endif
    @if($tia->ropa)
    <div class="meta-row"><span class="lbl">RoPA Terkait</span>: {{ $tia->ropa->registration_number }} — {{ $tia->ropa->processing_activity }}</div>
    @endif
    @if($tia->vendor)
    <div class="meta-row"><span class="lbl">Vendor</span>: {{ $tia->vendor->name }}</div>
    @endif
    <div class="meta-row"><span class="lbl">Volume / Frekuensi</span>: {{ $tia->transfer_volume ?? '—' }} / {{ $tia->transfer_frequency ?? '—' }}</div>
    <div class="meta-row"><span class="lbl">Status</span>: <span class="badge b-blue">{{ strtoupper($tia->status) }}</span></div>

    <div style="margin-top: 18px; text-align: center;">
        <span class="verdict-pill {{ $verdictClass }}">{{ $verdictLabel }}</span>
        @if($overallRisk !== null)
        <div style="margin-top: 8px; font-size: 10pt; color: #64748b;">
            Skor Risiko Residual: <strong>{{ number_format($overallRisk, 2) }}</strong> / 10
            · Level: <strong>{{ strtoupper($riskLevel ?? '—') }}</strong>
        </div>
        @endif
    </div>
</div>

<h2>1. Konteks Transfer</h2>
@if($tia->transfer_basis)
<div class="meta-row"><span class="lbl">Dasar Transfer</span>: {{ ucwords(str_replace('_', ' ', $tia->transfer_basis)) }}
    @if($tia->transfer_basis_other) — {{ $tia->transfer_basis_other }}@endif
</div>
@endif
@if(is_array($tia->transfer_details) && !empty($tia->transfer_details))
<table class="grid">
    <thead><tr><th>Aspek</th><th>Detail</th></tr></thead>
    <tbody>
        @foreach($tia->transfer_details as $key => $val)
        <tr>
            <td>{{ ucfirst(str_replace('_', ' ', (string) $key)) }}</td>
            <td>{{ is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<h2>2. Penilaian Risiko (6 Metrik)</h2>
<p class="muted" style="margin-bottom: 6px;">Skor 1-10 — semakin tinggi, semakin berisiko.</p>
<table class="grid">
    <thead><tr><th style="width: 60%;">Metrik Risiko</th><th>Skor</th><th>Visual</th></tr></thead>
    <tbody>
        @foreach($riskMetrics as $key => $label)
            @php $val = $tia->$key; @endphp
            <tr>
                <td>{{ $label }}</td>
                <td><strong>{{ $val ?? '—' }}</strong> / 10</td>
                <td>
                    @if($val !== null)
                    <div class="ruler-bar"><div class="ruler-fill" style="width: {{ ((int) $val / 10) * 100 }}%; background: {{ $riskColor($val) }};"></div></div>
                    @else
                    <span class="muted">belum diisi</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<h2>3. Penilaian Keamanan (Mitigasi)</h2>
<p class="muted" style="margin-bottom: 6px;">Skor 1-10 — semakin tinggi, semakin baik. Akan mengurangi skor risiko residual.</p>
<table class="grid">
    <thead><tr><th style="width: 60%;">Metrik Keamanan</th><th>Skor</th><th>Visual</th></tr></thead>
    <tbody>
        @foreach($secMetrics as $key => $label)
            @php $val = $tia->$key; @endphp
            <tr>
                <td>{{ $label }}</td>
                <td><strong>{{ $val ?? '—' }}</strong> / 10</td>
                <td>
                    @if($val !== null)
                    <div class="ruler-bar"><div class="ruler-fill" style="width: {{ ((int) $val / 10) * 100 }}%; background: {{ $secColor($val) }};"></div></div>
                    @else
                    <span class="muted">belum diisi</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<h2>4. Kerangka Hukum & Langkah Tambahan</h2>
@if(is_array($tia->legal_framework) && !empty($tia->legal_framework))
<ul>
    @foreach($tia->legal_framework as $item)
    <li>{{ is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string) $item }}</li>
    @endforeach
</ul>
@endif

@if(is_array($tia->supplementary_measures) && !empty($tia->supplementary_measures))
<h3>Langkah Tambahan (Supplementary Measures)</h3>
<ul>
    @foreach($tia->supplementary_measures as $item)
    <li>{{ is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE) : (string) $item }}</li>
    @endforeach
</ul>
@endif

<h2>5. Kesimpulan</h2>
@if(!empty($tia->conclusion_notes))
<div class="callout">{{ $tia->conclusion_notes }}</div>
@else
<p class="muted">Belum ada catatan dari approver.</p>
@endif

<h2>Workflow RACI</h2>
<div class="raci-grid">
    <div class="raci-cell">
        <div class="role">Maker</div>
        <div class="who">{{ optional($tia->maker)->name ?? '—' }}</div>
        <div class="when">{{ optional($tia->submitted_at)->format('d M Y · H:i') ?? 'Belum disubmit' }}</div>
    </div>
    <div class="raci-cell">
        <div class="role">Checker</div>
        <div class="who">{{ optional($tia->checker)->name ?? '—' }}</div>
        <div class="when">{{ optional($tia->checked_at)->format('d M Y · H:i') ?? 'Belum dicek' }}</div>
    </div>
    <div class="raci-cell">
        <div class="role">Approver</div>
        <div class="who">{{ optional($tia->approver)->name ?? '—' }}</div>
        <div class="when">{{ optional($tia->approved_at)->format('d M Y · H:i') ?? 'Belum disetujui' }}</div>
    </div>
</div>

<p class="stamp-id" style="margin-top: 18px;">
    Stamp: {{ strtoupper(substr(hash('sha256', $tia->id . '|tia|' . $tia->updated_at), 0, 16)) }}
</p>
@endsection
