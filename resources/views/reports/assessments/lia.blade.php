@extends('reports.assessments._layout')
@section('title', 'Legitimate Interest Assessment - ' . $lia->lia_code)
@section('doc-id', $lia->lia_code)

@php
    $verdictClass = match($verdict) {
        \App\Models\LiaAssessment::VERDICT_PASS => 'v-pass',
        \App\Models\LiaAssessment::VERDICT_FAIL => 'v-fail',
        default => 'v-pending',
    };
    $purposeTest = $lia->purpose_test ?? [];
    $necessityTest = $lia->necessity_test ?? [];
    $balancingTest = $lia->balancing_test ?? [];
@endphp

@section('content')
<h1 class="doc-title">Legitimate Interest Assessment</h1>
<p class="doc-sub">UU PDP No. 27 Tahun 2022 · Pasal 20 Ayat 2(f) — Kepentingan Sah</p>

<div class="doc-frame">
    <div class="meta-row"><span class="lbl">Kode LIA</span>: <strong>{{ $lia->lia_code }}</strong></div>
    <div class="meta-row"><span class="lbl">Judul</span>: <strong>{{ $lia->title }}</strong></div>
    <div class="meta-row"><span class="lbl">Aktivitas Pemrosesan</span>: {{ $lia->processing_activity ?? '—' }}</div>
    @if($lia->ropa)
    <div class="meta-row"><span class="lbl">RoPA Terkait</span>: {{ $lia->ropa->registration_number }} — {{ $lia->ropa->processing_activity }}</div>
    @endif
    <div class="meta-row"><span class="lbl">Status</span>: <span class="badge b-blue">{{ strtoupper($lia->status) }}</span></div>
    <div class="meta-row"><span class="lbl">Tanggal Submit</span>: {{ optional($lia->submitted_at)->format('d F Y · H:i') ?? '—' }}</div>
    <div class="meta-row"><span class="lbl">Tanggal Disetujui</span>: {{ optional($lia->approved_at)->format('d F Y · H:i') ?? '—' }}</div>

    <div style="margin-top: 18px; text-align: center;">
        <span class="verdict-pill {{ $verdictClass }}">{{ $verdictLabel }}</span>
        @if($lia->overall_score !== null)
        <div style="margin-top: 8px; font-size: 10pt; color: #64748b;">
            Skor Overall: <strong>{{ number_format((float) $lia->overall_score, 2) }}</strong> / 10
        </div>
        @endif
    </div>
</div>

<h2>1. Purpose Test (Uji Tujuan)</h2>
<div class="meta-row"><span class="lbl">Verdict</span>:
    @if($lia->conclusion_purpose === 'lulus')
        <span class="badge b-green">LULUS</span>
    @elseif($lia->conclusion_purpose === 'tidak_lulus')
        <span class="badge b-red">TIDAK LULUS</span>
    @else
        <span class="badge b-blue">BELUM DIPUTUSKAN</span>
    @endif
</div>
@if(!empty($lia->legitimate_interest_basis))
<div class="callout info">
    <strong>Dasar Kepentingan Sah:</strong><br>{{ $lia->legitimate_interest_basis }}
</div>
@endif
@if(!empty($lia->legitimate_interest_reason))
<p style="font-size:10pt;"><strong>Alasan:</strong> {{ $lia->legitimate_interest_reason }}</p>
@endif
@if(!empty($purposeTest))
<table class="grid">
    <thead><tr><th>Pertanyaan</th><th>Jawaban</th></tr></thead>
    <tbody>
        @foreach($purposeTest as $key => $val)
            @if(is_array($val) && isset($val['question']))
                <tr>
                    <td>{{ $val['question'] }}</td>
                    <td>{{ is_array($val['answer'] ?? null) ? implode(', ', $val['answer']) : ($val['answer'] ?? '—') }}</td>
                </tr>
            @elseif(!is_array($val))
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', (string) $key)) }}</td>
                    <td>{{ $val }}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
@endif

<h2>2. Necessity Test (Uji Kebutuhan)</h2>
<div class="meta-row"><span class="lbl">Verdict</span>:
    @if($lia->conclusion_necessity === 'lulus')
        <span class="badge b-green">LULUS</span>
    @elseif($lia->conclusion_necessity === 'tidak_lulus')
        <span class="badge b-red">TIDAK LULUS</span>
    @else
        <span class="badge b-blue">BELUM DIPUTUSKAN</span>
    @endif
</div>
@if(!empty($necessityTest))
<table class="grid">
    <thead><tr><th>Pertanyaan</th><th>Jawaban</th></tr></thead>
    <tbody>
        @foreach($necessityTest as $key => $val)
            @if(is_array($val) && isset($val['question']))
                <tr>
                    <td>{{ $val['question'] }}</td>
                    <td>{{ is_array($val['answer'] ?? null) ? implode(', ', $val['answer']) : ($val['answer'] ?? '—') }}</td>
                </tr>
            @elseif(!is_array($val))
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', (string) $key)) }}</td>
                    <td>{{ $val }}</td>
                </tr>
            @endif
        @endforeach
    </tbody>
</table>
@endif

<h2>3. Balancing Test (Uji Keseimbangan)</h2>
<div class="meta-row"><span class="lbl">Verdict</span>:
    @if($lia->conclusion_balancing === 'lulus')
        <span class="badge b-green">LULUS</span>
    @elseif($lia->conclusion_balancing === 'tidak_lulus')
        <span class="badge b-red">TIDAK LULUS</span>
    @else
        <span class="badge b-blue">BELUM DIPUTUSKAN</span>
    @endif
</div>
@if($lia->subject_loses_control)
<div class="callout warn">
    <strong>Catatan Kritis:</strong> Subjek data dinilai kehilangan kontrol atas data pribadinya.
    @if($lia->subject_loses_control_reason)
        <br><span style="font-size:9.5pt;">{{ $lia->subject_loses_control_reason }}</span>
    @endif
</div>
@endif
@if(!empty($lia->balancing_risk_events) && is_array($lia->balancing_risk_events))
<h3>Risk Register</h3>
<table class="grid">
    <thead>
        <tr>
            <th>Risiko</th>
            <th>Likelihood</th>
            <th>Impact</th>
            <th>Skor</th>
            <th>Mitigasi</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lia->balancing_risk_events as $row)
            @php
                $likelihood = (int) ($row['likelihood'] ?? 0);
                $impact = (int) ($row['impact'] ?? 0);
                $riskScore = $likelihood * $impact;
                $riskBadge = $riskScore >= 60 ? 'b-red' : ($riskScore >= 30 ? 'b-amber' : 'b-green');
            @endphp
            <tr>
                <td>{{ $row['risk'] ?? $row['name'] ?? '—' }}</td>
                <td>{{ $likelihood }}</td>
                <td>{{ $impact }}</td>
                <td><span class="badge {{ $riskBadge }}">{{ $riskScore }}</span></td>
                <td>{{ $row['mitigation'] ?? '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

<h2>4. Kesimpulan & Catatan Approver</h2>
@if(!empty($lia->conclusion_notes))
<div class="callout">{{ $lia->conclusion_notes }}</div>
@else
<p class="muted">Belum ada catatan dari approver.</p>
@endif

<h2>Workflow RACI</h2>
<div class="raci-grid">
    <div class="raci-cell">
        <div class="role">Maker</div>
        <div class="who">{{ optional($lia->maker)->name ?? '—' }}</div>
        <div class="when">{{ optional($lia->submitted_at)->format('d M Y · H:i') ?? 'Belum disubmit' }}</div>
    </div>
    <div class="raci-cell">
        <div class="role">Checker</div>
        <div class="who">{{ optional($lia->checker)->name ?? '—' }}</div>
        <div class="when">{{ optional($lia->checked_at)->format('d M Y · H:i') ?? 'Belum dicek' }}</div>
    </div>
    <div class="raci-cell">
        <div class="role">Approver</div>
        <div class="who">{{ optional($lia->approver)->name ?? '—' }}</div>
        <div class="when">{{ optional($lia->approved_at)->format('d M Y · H:i') ?? 'Belum disetujui' }}</div>
    </div>
</div>

<p class="stamp-id" style="margin-top: 18px;">
    Stamp: {{ strtoupper(substr(hash('sha256', $lia->id . '|lia|' . $lia->updated_at), 0, 16)) }}
</p>
@endsection
