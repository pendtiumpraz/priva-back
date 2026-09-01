@extends('reports.breach._layout')
@section('title', 'Pengumuman Publik — Insiden Pelindungan Data Pribadi')
@section('doc-id', $breach->incident_code ?? $today)

@section('content')

@php
    $steps = [];
    $ca = $breach->containment_actions ?? null;
    if (is_array($ca)) {
        foreach ($ca as $c) { if (is_string($c) && trim($c) !== '') $steps[] = trim($c); }
    } elseif (is_string($ca) && trim($ca) !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $ca) as $line) { if (trim($line) !== '') $steps[] = trim($line); }
    }
    foreach (($breach->containment_checklist ?? []) as $item) {
        if (is_array($item)) {
            $label = $item['label'] ?? $item['text'] ?? $item['step'] ?? $item['title'] ?? null;
            $done = $item['done'] ?? $item['completed'] ?? $item['checked'] ?? $item['is_done'] ?? false;
            if ($label && $done) $steps[] = $label;
        } elseif (is_string($item) && trim($item) !== '') {
            $steps[] = trim($item);
        }
    }
    $steps = array_values(array_unique($steps));
    $affected = array_values(array_filter((array) ($breach->affected_data_types ?? []), fn ($v) => is_string($v) && trim($v) !== ''));
    $grounds = (array) ($breach->public_notification_grounds ?? []);
@endphp

<div style="text-align: center; margin-bottom: 20px;">
    <h1 style="text-transform: uppercase;">Pengumuman Publik</h1>
    <h2 style="margin-top: 4px;">Insiden Pelindungan Data Pribadi</h2>
    <p class="muted">{{ $orgName }}</p>
    <p class="muted" style="font-size: 9pt;">Pasal 115 PP No. 33 Tahun 2026 tentang Pelindungan Data Pribadi</p>
</div>

<p>{{ $today }}</p>

<p style="text-align: justify;">
    Dengan ini <strong>{{ $orgName }}</strong> menyampaikan pemberitahuan kepada masyarakat mengenai
    terjadinya insiden Kegagalan Pelindungan Data Pribadi. Pemberitahuan ini disampaikan secara terbuka
    sesuai amanat Pasal 115 PP No. 33 Tahun 2026, mengingat insiden ini
    @if(in_array('pelayanan_publik', $grounds, true) && in_array('kepentingan_masyarakat', $grounds, true))
        berpotensi mengganggu pelayanan publik dan berdampak serius terhadap kepentingan masyarakat.
    @elseif(in_array('pelayanan_publik', $grounds, true))
        berpotensi mengganggu pelayanan publik.
    @elseif(in_array('kepentingan_masyarakat', $grounds, true))
        berdampak serius terhadap kepentingan masyarakat.
    @else
        dinilai perlu diketahui masyarakat.
    @endif
</p>

<h2>Apa yang Terjadi</h2>
<p style="text-align: justify;">
    @if($breach->title)<strong>{{ $breach->title }}.</strong> @endif
    {{ $breach->description ?: 'Kami mendeteksi adanya insiden yang berpotensi memengaruhi keamanan sebagian data pribadi yang kami kelola.' }}
</p>
<ul style="text-align: justify;">
    @if($breach->detected_at)
        <li>Insiden terdeteksi pada <strong>{{ optional($breach->detected_at)->locale('id')->isoFormat('D MMMM Y') }}</strong>.</li>
    @endif
    @if(count($affected))
        <li>Jenis data yang mungkin terdampak: <strong>{{ implode(', ', $affected) }}</strong>.</li>
    @endif
    @if($breach->affected_subjects_count)
        <li>Perkiraan jumlah subjek data yang mungkin terdampak: <strong>{{ number_format($breach->affected_subjects_count, 0, ',', '.') }}</strong>.</li>
    @endif
</ul>

<h2>Langkah Penanganan yang Telah Dilakukan</h2>
<ol style="text-align: justify;">
    @forelse($steps as $s)
        <li>{{ $s }}</li>
    @empty
        <li>Mengisolasi sumber insiden dan mengamankan sistem yang terdampak.</li>
        <li>Melakukan investigasi menyeluruh untuk memastikan cakupan dan penyebab insiden.</li>
        <li>Memperkuat kontrol keamanan untuk mencegah kejadian serupa terulang.</li>
    @endforelse
</ol>
@if($breach->contained_at)
    <p style="text-align: justify;">Insiden telah <strong>dikendalikan</strong> pada
        {{ optional($breach->contained_at)->locale('id')->isoFormat('D MMMM Y') }}.</p>
@endif

<h2>Imbauan bagi Masyarakat</h2>
<ol style="text-align: justify;">
    <li>Ubah kata sandi akun yang terkait dengan layanan kami dan hindari penggunaan kata sandi yang sama di banyak layanan.</li>
    <li>Aktifkan autentikasi dua faktor (2FA) apabila tersedia.</li>
    <li>Waspadai email/SMS/telepon mencurigakan yang mengatasnamakan {{ $orgName }}. Kami tidak pernah meminta kata sandi atau OTP.</li>
</ol>

<div class="callout">
    <strong>Kanal Informasi.</strong> Pertanyaan lebih lanjut dapat disampaikan melalui Pejabat/Petugas
    Pelindungan Data Pribadi (PPDP/DPO) kami:
    @if(!empty($dpoName)) {{ $dpoName }}@endif
    @if(!empty($dpoEmail)) · {{ $dpoEmail }}@endif
    @if(!empty($dpoPhone)) · {{ $dpoPhone }}@endif
</div>

<p style="text-align: justify;">
    Kami menyampaikan permohonan maaf atas ketidaknyamanan ini dan berkomitmen untuk terus meningkatkan
    keamanan layanan serta pelindungan data pribadi masyarakat.
</p>

<div class="sig-block">
    <p>Hormat kami,</p>
    <div class="sig-line"></div>
    <p><strong>{{ $orgName }}</strong></p>
</div>

<p class="muted" style="margin-top: 24px; font-size: 8pt;">
    Dokumen digenerate otomatis oleh Privasimu Nexus pada {{ $generatedAt }} oleh {{ $generatedBy }}.
</p>

@endsection
