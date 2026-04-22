@extends('reports.breach._layout')
@section('title', 'Surat Notifikasi KOMDIGI — ' . $breach->incident_code)
@section('doc-id', $breach->incident_code . ' · ' . $today)

@section('content')

<div style="text-align: center; margin-bottom: 20px;">
    <h1 style="text-transform: uppercase;">Surat Notifikasi Pelanggaran Data Pribadi</h1>
    <p class="muted">Pasal 46 UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi</p>
</div>

<div style="margin-bottom: 20px;">
    <p>{{ $today }}</p>
    <p>Nomor: {{ $breach->incident_code }}</p>
    <p>Lampiran: —</p>
    <p>Perihal: <strong>Notifikasi Pelanggaran Data Pribadi</strong></p>
</div>

<div style="margin-bottom: 16px;">
    <p>Kepada Yth.</p>
    <p><strong>Kementerian Komunikasi dan Digital (KOMDIGI)</strong><br>
    Direktorat Jenderal Aplikasi Informatika<br>
    Jakarta</p>
</div>

<p>Dengan hormat,</p>

<p>Menindaklanjuti kewajiban notifikasi dalam Pasal 46 UU No. 27 Tahun 2022 tentang
Pelindungan Data Pribadi, dengan ini <strong>{{ $orgName }}</strong>@if($orgAddress) yang berkedudukan di {{ $orgAddress }}@endif
menyampaikan notifikasi mengenai insiden pelanggaran data pribadi dengan detail sebagai berikut:</p>

<table class="meta">
    <tr><td>1. Kode Insiden</td><td>: <strong>{{ $breach->incident_code }}</strong></td></tr>
    <tr><td>2. Judul Insiden</td><td>: {{ $breach->title }}</td></tr>
    <tr><td>3. Severity</td><td>:
        <span class="badge {{ $breach->severity === 'critical' || $breach->severity === 'high' ? 'b-red' : ($breach->severity === 'medium' ? 'b-amber' : 'b-blue') }}">
            {{ strtoupper($breach->severity) }}
        </span>
    </td></tr>
    <tr><td>4. Kategori Insiden</td><td>: {{ $breach->case_type ?? '—' }}</td></tr>
    <tr><td>5. Tanggal & Waktu Deteksi</td><td>: {{ $breach->detected_at?->locale('id')->isoFormat('D MMMM Y · HH:mm') ?? '—' }} WIB</td></tr>
    <tr><td>6. Sumber Pelaporan</td><td>: {{ $breach->source ?? '—' }}</td></tr>
    <tr><td>7. Jumlah Subjek Data Terdampak</td><td>: {{ number_format($breach->affected_subjects_count ?? 0, 0, ',', '.') }} orang</td></tr>
    <tr><td>8. Kategori Data Terdampak</td><td>:
        @if(is_array($breach->affected_data_types))
            {{ implode(', ', $breach->affected_data_types) }}
        @else
            {{ $breach->affected_data_types ?? '—' }}
        @endif
    </td></tr>
</table>

<h2>Deskripsi Insiden</h2>
<p style="text-align: justify;">{{ $breach->description ?? '—' }}</p>

<h2>Analisis Root Cause</h2>
<p style="text-align: justify;">{{ $breach->root_cause ?? 'Sedang dalam proses investigasi.' }}</p>

<h2>Tindakan Containment yang Telah Dilakukan</h2>
@if(is_array($breach->containment_checklist) && count($breach->containment_checklist) > 0)
    <ul>
        @foreach($breach->containment_checklist as $key => $step)
            @php
                $done = is_array($step) ? ($step['done'] ?? false) : (bool) $step;
                $label = is_array($step) ? ($step['label'] ?? $key) : $key;
                $notes = is_array($step) ? ($step['notes'] ?? null) : null;
            @endphp
            @if($done)
                <li>
                    <strong>{{ $label }}</strong>
                    @if($notes)<br><span class="muted">{{ $notes }}</span>@endif
                </li>
            @endif
        @endforeach
    </ul>
@else
    <p class="muted">Tidak ada tindakan containment yang tercatat.</p>
@endif

<h2>Rencana Remediasi</h2>
<p style="text-align: justify;">{{ $breach->remediation_plan ?? 'Rencana remediasi sedang disusun dan akan disampaikan pada laporan lanjutan.' }}</p>

<div class="callout info">
    Notifikasi ini disampaikan dalam kurun waktu 3×24 jam sejak insiden terdeteksi, sesuai dengan
    ketentuan Pasal 46 UU No. 27 Tahun 2022. Kami bertanggung jawab atas insiden ini dan bersedia
    dikoordinasikan lebih lanjut oleh KOMDIGI.
</div>

<h2>Narahubung</h2>
<table class="meta">
    <tr><td>Nama</td><td>: <strong>{{ $dpoName }}</strong></td></tr>
    <tr><td>Jabatan</td><td>: Data Protection Officer — {{ $orgName }}</td></tr>
    <tr><td>Email</td><td>: {{ $dpoEmail }}</td></tr>
    @if($dpoPhone)<tr><td>Telepon</td><td>: {{ $dpoPhone }}</td></tr>@endif
</table>

<div class="sig-block">
    <p>Hormat kami,</p>
    <div class="sig-line"></div>
    <p><strong>{{ $dpoName }}</strong><br>
    Data Protection Officer<br>
    {{ $orgName }}</p>
</div>

<p class="muted" style="margin-top: 30px; font-size: 8pt;">
    Dokumen digenerate otomatis oleh Privasimu Nexus pada {{ $generatedAt }} oleh {{ $generatedBy }}.
    Dokumen ini sah tanpa tanda tangan basah apabila disertai tanda tangan digital yang valid.
</p>

@endsection
