@extends('reports.dsr._layout')
@section('title', 'Sertifikat Penyelesaian Permintaan')
@section('doc-id', $dsr->request_id)

@section('content')
<div class="cert-frame">
    <p class="cert-title">SERTIFIKAT PENYELESAIAN<br>PERMINTAAN HAK SUBJEK DATA</p>
    <p class="cert-sub">Berdasarkan UU No. 27 Tahun 2022 tentang Perlindungan Data Pribadi</p>

    <p style="text-align:justify;">
        Dengan ini {{ $orgName }} menyatakan telah memproses dan menyelesaikan permintaan
        hak subjek data pribadi sebagai berikut:
    </p>

    <div style="margin: 16px 0;">
        <div class="field-row"><span class="field-label">Nomor Permintaan</span>: <strong>{{ $dsr->request_id }}</strong></div>
        <div class="field-row"><span class="field-label">Jenis Permintaan</span>: <strong>{{ $requestTypeLabel }}</strong></div>
        <div class="field-row"><span class="field-label">Pemohon</span>: {{ $maskedEmail }}</div>
        <div class="field-row"><span class="field-label">Diajukan pada</span>: {{ optional($dsr->created_at)->format('d F Y H:i') }} WIB</div>
        <div class="field-row"><span class="field-label">Diverifikasi pada</span>: {{ optional($dsr->verified_at)->format('d F Y H:i') }} WIB</div>
        <div class="field-row"><span class="field-label">Diselesaikan pada</span>: {{ optional($dsr->closed_at ?? now())->format('d F Y H:i') }} WIB</div>
    </div>

    <h3 style="margin-top:14px;">Sistem yang Terdampak</h3>
    <table class="scope-table">
        <thead>
            <tr><th>Sistem Informasi</th><th>Tindakan</th><th>Status</th></tr>
        </thead>
        <tbody>
            @foreach($scopeRows as $row)
                <tr>
                    <td>{{ $row['system_name'] }}</td>
                    <td>{{ $row['action_label'] }}</td>
                    <td><span class="check">{{ $row['status'] }}</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin-top:14px; text-align:justify; font-size:9.5pt;">
        Sertifikat ini diterbitkan sebagai bukti pemenuhan kewajiban pengendali data pribadi
        atas permintaan Anda. Apabila Anda memiliki pertanyaan, silakan hubungi
        @if($orgEmail) {{ $orgEmail }} @endif.
    </p>

    <div class="signoff">
        <p>Hormat kami,</p>
        <div class="sig-line"></div>
        <p><strong>{{ $dpoName ?? 'Data Protection Officer' }}</strong><br>{{ $orgName }}</p>
        <p class="stamp-id">Verifikasi: {{ $verificationStamp }}</p>
    </div>
</div>
@endsection
