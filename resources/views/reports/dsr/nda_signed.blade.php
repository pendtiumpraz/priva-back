@extends('reports.dsr._layout')
@section('title', 'NDA Tertanda Tangani')
@section('doc-id', $dsr->request_id)

@section('content')
<div class="cert-frame">
    <p class="cert-title">NON-DISCLOSURE AGREEMENT</p>
    <p class="cert-sub">Bukti tanda tangan elektronik · UU ITE No. 11/2008 · UU PDP No. 27/2022</p>

    <div style="margin: 14px 0;">
        <div class="field-row"><span class="field-label">Nomor Permintaan</span>: <strong>{{ $dsr->request_id }}</strong></div>
        <div class="field-row"><span class="field-label">Pengendali Data</span>: {{ $orgName }}</div>
        <div class="field-row"><span class="field-label">Pemohon</span>: {{ $signerName }} &lt;{{ $signerEmail }}&gt;</div>
    </div>

    <h3>Isi Perjanjian</h3>
    <div style="background:#f8fafc; padding:12px 14px; border:1px solid #e2e8f0; white-space:pre-wrap; font-size:9.5pt; line-height:1.55;">{{ $ndaText }}</div>

    <h3>Detail Tanda Tangan Elektronik</h3>
    <div class="field-row"><span class="field-label">Nama (typed)</span>: <strong>{{ $signerName }}</strong></div>
    <div class="field-row"><span class="field-label">Tanda tangan</span>: <span style="font-family:'Cedarville Cursive','Lucida Handwriting',cursive; font-size:22pt; color:#1e3a8a;">{{ $typedSignature }}</span></div>
    <div class="field-row"><span class="field-label">Waktu</span>: {{ $signedAt->format('d F Y H:i:s') }} WIB</div>
    <div class="field-row"><span class="field-label">IP Address</span>: {{ $signedIp }}</div>
    <div class="field-row" style="margin-top:6px;"><span class="field-label">User Agent</span>: <span style="font-size:8pt; color:#64748b;">{{ $userAgent }}</span></div>

    <p style="margin-top:18px; font-size:9.5pt; text-align:justify;">
        Dokumen ini diterbitkan sebagai bukti tanda tangan elektronik atas Non-Disclosure
        Agreement antara <strong>{{ $signerName }}</strong> dan <strong>{{ $orgName }}</strong>.
        Hash verifikasi dokumen disertakan untuk integritas:
    </p>
    <p style="font-family:monospace; font-size:9pt; color:#475569; word-break:break-all;">SHA-256 stamp: {{ $verificationStamp }}</p>

    <div class="signoff">
        <p style="font-size:9.5pt;">Disahkan secara elektronik oleh kedua pihak melalui Privasimu Nexus.</p>
        <p class="stamp-id">Verifikasi: {{ $verificationStamp }} · {{ $signedAt->format('YmdHis') }}</p>
    </div>
</div>
@endsection
