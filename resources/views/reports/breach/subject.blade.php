@extends('reports.breach._layout')
@section('title', 'Pemberitahuan Insiden Pelindungan Data Pribadi')
@section('doc-id', $breach->incident_code ?? $today)

@section('content')

@php
    // Susun daftar langkah penanganan dari containment_actions (teks) +
    // item containment_checklist yang sudah selesai. Defensif terhadap shape.
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
@endphp

<div style="text-align: center; margin-bottom: 20px;">
    <h1>Pemberitahuan Insiden Pelindungan Data Pribadi</h1>
    <p class="muted">{{ $orgName }}</p>
</div>

<p>{{ $today }}</p>

<p>Kepada Yth.<br>
<strong>Subjek Data Pribadi {{ $orgName }}</strong></p>

<p>Dengan hormat,</p>

<p style="text-align: justify;">
    Sebagai bentuk transparansi dan tanggung jawab kami dalam melindungi data pribadi Anda — sesuai
    amanat Undang-Undang Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi — melalui surat ini
    {{ $orgName }} menyampaikan pemberitahuan mengenai sebuah insiden keamanan data yang baru-baru ini
    kami tangani. Kami memahami informasi ini mungkin menimbulkan kekhawatiran. Perlu kami sampaikan
    bahwa <strong>situasi telah kami kendalikan</strong> dan kami telah mengambil langkah-langkah yang
    diperlukan untuk melindungi data serta kepentingan Anda.
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
    <li>Kami menekankan bahwa tidak semua data subjek terdampak, dan kami terus memantau perkembangan situasi.</li>
</ul>

<h2>Langkah Penanganan yang Telah Kami Lakukan</h2>
<p style="text-align: justify;">
    Begitu insiden diketahui, tim kami segera bertindak untuk membatasi dampak dan mengamankan sistem:
</p>
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
    <p style="text-align: justify;">
        Insiden ini telah berhasil <strong>dikendalikan dan ditangani</strong> pada
        {{ optional($breach->contained_at)->locale('id')->isoFormat('D MMMM Y') }}.
    </p>
@endif
@if($breach->remediation_plan)
    <p style="text-align: justify;"><strong>Rencana perbaikan lanjutan:</strong> {{ $breach->remediation_plan }}</p>
@endif

<h2>Langkah Pengamanan yang Dapat Anda Lakukan</h2>
<p style="text-align: justify;">Sebagai langkah kehati-hatian tambahan, kami menyarankan Anda untuk:</p>
<ol style="text-align: justify;">
    <li><strong>Ubah kata sandi akun Anda</strong> yang terkait dengan layanan kami. Gunakan kombinasi
        huruf besar, huruf kecil, angka, dan simbol minimal 12 karakter.</li>
    <li><strong>Aktifkan autentikasi dua faktor (2FA)</strong> jika belum diaktifkan.</li>
    <li><strong>Tinjau riwayat aktivitas/login akun Anda</strong> dan laporkan jika ada hal yang tidak Anda kenali.</li>
    <li><strong>Waspada terhadap email/SMS/telepon</strong> mencurigakan yang mengatasnamakan kami.
        {{ $orgName }} tidak pernah meminta kata sandi atau OTP melalui kanal tersebut.</li>
    <li><strong>Hindari memakai kata sandi yang sama</strong> di beberapa layanan berbeda.</li>
</ol>

<div class="callout">
    <strong>Kami siap membantu.</strong> Apabila Anda memiliki pertanyaan atau memerlukan informasi
    tambahan terkait insiden ini, silakan hubungi Pejabat Pelindungan Data Pribadi (DPO) kami:
    @if(!empty($dpoName)) {{ $dpoName }}@endif
    @if(!empty($dpoEmail)) · email {{ $dpoEmail }}@endif
    @if(!empty($dpoPhone)) · telepon {{ $dpoPhone }}@endif
    @if($orgEmail) · {{ $orgEmail }}@endif
    @if($orgWebsite) · {{ $orgWebsite }}@endif
</div>

<p style="text-align: justify;">
    Kami menyampaikan permohonan maaf yang sebesar-besarnya atas ketidaknyamanan ini. Menjaga kepercayaan
    dan melindungi data pribadi Anda adalah prioritas kami, dan kami berkomitmen untuk terus meningkatkan
    keamanan layanan kami.
</p>

<div class="sig-block">
    <p>Hormat kami,</p>
    <div class="sig-line"></div>
    <p><strong>{{ $dpoName ?? 'Pejabat Pelindungan Data Pribadi (DPO)' }}</strong><br>
    {{ $orgName }}</p>
</div>

@endsection
