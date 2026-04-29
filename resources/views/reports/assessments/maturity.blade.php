@extends('reports.assessments._layout')
@section('title', 'Maturity Assessment - ' . $assessment->title)

@php
    $domainLabels = \App\Models\MaturityQuestion::DOMAIN_LABELS;
    $levelColors = [
        1 => '#ef4444',
        2 => '#f59e0b',
        3 => '#eab308',
        4 => '#22c55e',
    ];
    $levelColor = $levelColors[$assessment->overall_level] ?? '#94a3b8';
    $domainScores = $assessment->domain_scores ?? [];
@endphp

@section('content')
<h1 class="doc-title">Maturity Assessment UU PDP</h1>
<p class="doc-sub">Evaluasi Tingkat Kematangan Kepatuhan UU No. 27 Tahun 2022</p>

<div class="doc-frame">
    <div class="meta-row"><span class="lbl">Judul</span>: <strong>{{ $assessment->title }}</strong></div>
    <div class="meta-row"><span class="lbl">Versi</span>: {{ $assessment->version }}</div>
    <div class="meta-row"><span class="lbl">Metode Input</span>:
        @switch($assessment->input_method)
            @case('questionnaire') Kuesioner DPO @break
            @case('document') Upload Dokumen + AI @break
            @case('auto_derive') Auto-derive dari Data Nexus @break
            @default {{ $assessment->input_method }}
        @endswitch
    </div>
    <div class="meta-row"><span class="lbl">Status</span>: <span class="badge b-blue">{{ strtoupper($assessment->status) }}</span></div>
    <div class="meta-row"><span class="lbl">Tanggal Submit</span>: {{ optional($assessment->submitted_at)->format('d F Y · H:i') ?? '—' }}</div>
    @if($assessment->submitter)
    <div class="meta-row"><span class="lbl">Disubmit oleh</span>: {{ $assessment->submitter->name }}</div>
    @endif

    <div style="margin-top: 18px; text-align: center;">
        <div style="display: inline-block; padding: 16px 28px; border: 3px solid {{ $levelColor }}; border-radius: 12px; min-width: 280px;">
            <div style="font-size: 28pt; font-weight: 800; color: {{ $levelColor }}; line-height: 1;">
                {{ $assessment->overall_score !== null ? number_format((float) $assessment->overall_score, 2) : '—' }}
            </div>
            <div style="font-size: 9pt; color: #64748b;">SKOR OVERALL / 10</div>
            <div style="margin-top: 8px; font-size: 13pt; font-weight: 800; color: {{ $levelColor }}; text-transform: uppercase; letter-spacing: 1px;">
                LEVEL {{ $assessment->overall_level ?? '—' }} · {{ $levelLabel }}
            </div>
        </div>
    </div>
</div>

<h2>Skor per Domain</h2>
<table class="grid">
    <thead><tr><th style="width: 50%;">Domain</th><th>Skor</th><th>Visual</th></tr></thead>
    <tbody>
        @foreach($domainLabels as $key => $label)
            @php
                $score = $domainScores[$key] ?? null;
                $color = $score === null ? '#94a3b8' : ($score >= 9 ? '#22c55e' : ($score >= 7 ? '#eab308' : ($score >= 4 ? '#f59e0b' : '#ef4444')));
            @endphp
            <tr>
                <td>{{ $label }}</td>
                <td><strong>{{ $score !== null ? number_format((float) $score, 2) : '—' }}</strong> / 10</td>
                <td>
                    @if($score !== null)
                    <div class="ruler-bar"><div class="ruler-fill" style="width: {{ ((float) $score / 10) * 100 }}%; background: {{ $color }};"></div></div>
                    @else
                    <span class="muted">belum dinilai</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<h2>Detail Jawaban (18 Pertanyaan UU PDP)</h2>
@foreach($domainLabels as $domainKey => $domainLabel)
    @php $items = $responsesByDomain->get($domainKey, collect()); @endphp
    @if($items->isNotEmpty())
    <h3 style="margin-top: 14px;">{{ $domainLabel }}</h3>
    <table class="grid">
        <thead>
            <tr>
                <th style="width: 80px;">Kode</th>
                <th>Skor</th>
                <th>Sumber</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $r)
            <tr>
                <td><strong>{{ $r->question_code }}</strong></td>
                <td><strong>{{ $r->score }}</strong> / 10</td>
                <td>
                    @switch($r->source)
                        @case('manual') <span class="badge b-blue">Manual</span> @break
                        @case('auto_derive') <span class="badge b-green">Auto</span> @break
                        @case('document_ai') <span class="badge b-amber">AI</span> @break
                        @default <span class="muted">—</span>
                    @endswitch
                </td>
                <td>{{ $r->notes ?? '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
@endforeach

@if(!empty($assessment->recommendations) && is_array($assessment->recommendations))
<h2>Rekomendasi</h2>
<ul>
    @foreach($assessment->recommendations as $rec)
        <li>{{ is_array($rec) ? ($rec['text'] ?? json_encode($rec, JSON_UNESCAPED_UNICODE)) : (string) $rec }}</li>
    @endforeach
</ul>
@endif

<h2>Roadmap Naik Level</h2>
@php
    $nextLevel = ($assessment->overall_level ?? 1) + 1;
    $roadmap = [
        2 => 'Resmikan penunjukan DPO secara tertulis · Standardisasi consent template · Prosedur penanganan hak subjek data formal',
        3 => 'Audit kepatuhan internal berkala · Klausul perlindungan data di kontrak vendor · Tools pemantauan keamanan (SIEM, DLP)',
        4 => 'Privacy by Design dalam SDLC · Otomatisasi penghapusan retensi · Kepatuhan sebagai nilai kompetitif',
    ];
@endphp
@if(isset($roadmap[$nextLevel]))
<div class="callout info">
    <strong>Untuk naik ke Level {{ $nextLevel }}:</strong><br>
    {{ $roadmap[$nextLevel] }}
</div>
@elseif($assessment->overall_level >= 4)
<div class="callout">
    <strong>Sudah di Level Tertinggi (Optimized).</strong> Pertahankan dengan continuous improvement.
</div>
@endif

<p class="stamp-id" style="margin-top: 18px;">
    Stamp: {{ strtoupper(substr(hash('sha256', $assessment->id . '|maturity|' . $assessment->updated_at), 0, 16)) }}
</p>
@endsection
