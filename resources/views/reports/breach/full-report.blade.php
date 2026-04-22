@extends('reports.breach._layout')
@section('title', 'Breach Incident Report — ' . $breach->incident_code)
@section('doc-id', $breach->incident_code)

@section('content')

<div style="text-align: center; margin-bottom: 24px;">
    <h1>BREACH INCIDENT REPORT</h1>
    <p class="muted">Internal Documentation · {{ $orgName }}</p>
    <p style="margin-top: 4px;">
        <span class="badge {{ $breach->severity === 'critical' ? 'b-red' : ($breach->severity === 'high' ? 'b-red' : ($breach->severity === 'medium' ? 'b-amber' : 'b-blue')) }}">
            {{ strtoupper($breach->severity) }}
        </span>
        &nbsp;
        <span class="badge {{ $breach->status === 'closed' ? 'b-green' : ($breach->status === 'notification' ? 'b-red' : 'b-blue') }}">
            {{ strtoupper($breach->status) }}
        </span>
    </p>
</div>

<h2>Ringkasan</h2>
<table class="meta">
    <tr><td>Kode Insiden</td><td>: <strong>{{ $breach->incident_code }}</strong></td></tr>
    <tr><td>Judul</td><td>: {{ $breach->title }}</td></tr>
    <tr><td>Kategori Insiden</td><td>: {{ $breach->case_type ?? '—' }}</td></tr>
    <tr><td>Severity</td><td>: {{ strtoupper($breach->severity) }}</td></tr>
    <tr><td>Status Saat Ini</td><td>: {{ strtoupper($breach->status) }}</td></tr>
    <tr><td>Tanggal Deteksi</td><td>: {{ $breach->detected_at?->locale('id')->isoFormat('D MMMM Y · HH:mm') ?? '—' }}</td></tr>
    @if($breach->contained_at)<tr><td>Tanggal Containment</td><td>: {{ $breach->contained_at->locale('id')->isoFormat('D MMMM Y · HH:mm') }}</td></tr>@endif
    @if($breach->closed_at)<tr><td>Tanggal Ditutup</td><td>: {{ $breach->closed_at->locale('id')->isoFormat('D MMMM Y · HH:mm') }}</td></tr>@endif
    <tr><td>Sumber</td><td>: {{ $breach->source ?? '—' }}</td></tr>
    <tr><td>Jumlah Subjek Terdampak</td><td>: {{ number_format($breach->affected_subjects_count ?? 0, 0, ',', '.') }} orang</td></tr>
    <tr><td>Jenis Data Terdampak</td><td>:
        @if(is_array($breach->affected_data_types))
            {{ implode(', ', $breach->affected_data_types) }}
        @else
            {{ $breach->affected_data_types ?? '—' }}
        @endif
    </td></tr>
    @if($breach->notification_required)
    <tr><td>Notifikasi Wajib?</td><td>: Ya (Deadline {{ $breach->notification_deadline?->locale('id')->isoFormat('D MMM Y HH:mm') ?? '—' }})</td></tr>
    @endif
    @if($breach->notified_komdigi_at)
    <tr><td>KOMDIGI Dinotifikasi</td><td>: {{ $breach->notified_komdigi_at->locale('id')->isoFormat('D MMMM Y · HH:mm') }}</td></tr>
    @endif
    @if($breach->notified_subjects_at)
    <tr><td>Subjek Data Dinotifikasi</td><td>: {{ $breach->notified_subjects_at->locale('id')->isoFormat('D MMMM Y · HH:mm') }}</td></tr>
    @endif
</table>

<h2>Deskripsi Insiden</h2>
<p style="text-align: justify;">{{ $breach->description ?? '—' }}</p>

<h2>Root Cause Analysis</h2>
<p style="text-align: justify;">{{ $breach->root_cause ?? 'Sedang dalam investigasi.' }}</p>

<h2>Tindakan Containment</h2>
@if(is_array($breach->containment_checklist) && count($breach->containment_checklist) > 0)
    <table class="grid">
        <thead>
            <tr>
                <th style="width: 30px;">#</th>
                <th>Langkah</th>
                <th style="width: 80px;">Status</th>
                <th>Catatan</th>
                <th style="width: 100px;">Selesai</th>
            </tr>
        </thead>
        <tbody>
            @foreach($breach->containment_checklist as $key => $step)
                @php
                    $isObj = is_array($step);
                    $label = $isObj ? ($step['label'] ?? $key) : $key;
                    $done = $isObj ? ($step['done'] ?? false) : (bool) $step;
                    $category = $isObj ? ($step['category'] ?? '—') : '—';
                    $notes = $isObj ? ($step['notes'] ?? null) : null;
                    $completedAt = $isObj ? ($step['completed_at'] ?? null) : null;
                    $isCustom = $isObj && ($step['is_custom'] ?? false);
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <strong>{{ $label }}</strong>
                        @if($isObj)<br><span class="muted" style="font-size: 8pt;">{{ $category }}@if($isCustom) · custom @endif</span>@endif
                    </td>
                    <td>
                        @if($done)
                            <span class="badge b-green">DONE</span>
                        @else
                            <span class="badge b-amber">PENDING</span>
                        @endif
                    </td>
                    <td style="font-size: 9pt;">{{ $notes ?? '—' }}</td>
                    <td style="font-size: 9pt;">{{ $completedAt ? \Carbon\Carbon::parse($completedAt)->locale('id')->isoFormat('D MMM HH:mm') : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">Tidak ada checklist containment.</p>
@endif

<h2>Rencana Remediasi</h2>
<p style="text-align: justify;">{{ $breach->remediation_plan ?? 'Rencana remediasi sedang disusun.' }}</p>

<h2>Timeline Insiden</h2>
@if(is_array($breach->timeline_log) && count($breach->timeline_log) > 0)
    <table class="grid">
        <thead><tr><th style="width: 130px;">Waktu</th><th>Event</th></tr></thead>
        <tbody>
            @foreach($breach->timeline_log as $entry)
                <tr>
                    <td style="font-size: 9pt;">{{ $entry['time'] ?? '—' }}</td>
                    <td>{{ $entry['event'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">Belum ada entry timeline.</p>
@endif

<h2>Narahubung</h2>
<table class="meta">
    <tr><td>DPO</td><td>: {{ $dpoName }} · {{ $dpoEmail }}@if($dpoPhone) · {{ $dpoPhone }}@endif</td></tr>
    <tr><td>Organisasi</td><td>: {{ $orgName }}@if($orgAddress) · {{ $orgAddress }}@endif</td></tr>
</table>

<p class="muted" style="margin-top: 30px; font-size: 8pt;">
    Dokumen digenerate oleh Privasimu Nexus pada {{ $generatedAt }} oleh {{ $generatedBy }}.
    Laporan ini dapat dipakai sebagai dokumentasi internal dan lampiran audit compliance.
</p>

@endsection
