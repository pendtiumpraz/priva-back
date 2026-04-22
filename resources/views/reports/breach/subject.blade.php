@extends('reports.breach._layout')
@section('title', 'Himbauan Keamanan Akun')
@section('doc-id', $today)

@section('content')

{{--
    Intentionally does NOT mention the breach incident. Framed as routine
    security hygiene to prevent customer churn. For legal/compliance:
    include a generic reference to the company's Privacy Policy which
    already discloses possible incident handling.
--}}

<div style="text-align: center; margin-bottom: 20px;">
    <h1>Himbauan Keamanan Akun Anda</h1>
    <p class="muted">{{ $orgName }}</p>
</div>

<p>{{ $today }}</p>

<p>Kepada Yth.<br>
<strong>Pelanggan {{ $orgName }}</strong></p>

<p>Dengan hormat,</p>

<p style="text-align: justify;">
    Sebagai bagian dari komitmen {{ $orgName }} untuk terus menjaga keamanan data pribadi
    Anda, kami mengingatkan Anda untuk secara berkala melakukan tindakan pengamanan akun
    berikut:
</p>

<ol style="text-align: justify;">
    <li><strong>Ubah kata sandi akun Anda</strong> minimal setiap 3 bulan. Gunakan kombinasi
        huruf besar, huruf kecil, angka, dan simbol minimal 12 karakter.</li>
    <li><strong>Aktifkan autentikasi dua faktor (2FA)</strong> jika belum diaktifkan.
        2FA memberikan lapisan keamanan tambahan pada akun Anda.</li>
    <li><strong>Tinjau riwayat login terakhir</strong> pada profil Anda. Laporkan segera
        jika ada aktivitas yang tidak Anda kenali.</li>
    <li><strong>Waspada terhadap email/SMS phishing</strong> yang mengatasnamakan kami.
        {{ $orgName }} tidak pernah meminta password atau OTP melalui kanal ini.</li>
    <li><strong>Jangan gunakan kata sandi yang sama</strong> dengan akun lain. Apabila
        satu layanan mengalami kebocoran, akun Anda di layanan lain akan tetap aman.</li>
</ol>

<div class="callout">
    <strong>Butuh bantuan?</strong> Tim layanan pelanggan kami siap membantu Anda mengubah
    kata sandi atau mengaktifkan 2FA. Hubungi kami melalui kanal resmi:
    @if($orgEmail) email {{ $orgEmail }}@endif
    @if($orgPhone) · telepon {{ $orgPhone }}@endif
    @if($orgWebsite) · {{ $orgWebsite }}@endif
</div>

<p style="text-align: justify;">
    Terima kasih atas kepercayaan dan kerjasama Anda menjaga keamanan akun bersama kami.
</p>

<div class="sig-block">
    <p>Hormat kami,</p>
    <div class="sig-line"></div>
    <p><strong>Tim Customer Care</strong><br>
    {{ $orgName }}</p>
</div>

<p class="muted" style="margin-top: 30px; font-size: 8pt;">
    Surat ini merupakan pengingat keamanan rutin yang dikirimkan secara berkala kepada
    pelanggan kami. Apabila Anda tidak ingin menerima pengingat serupa, Anda dapat
    mengubah preferensi komunikasi di akun Anda.
</p>

@endsection
