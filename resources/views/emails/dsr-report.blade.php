<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"><title>Hasil Permohonan Subjek Data</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#1f2937; line-height:1.6;">
    <p>Yth. {{ $dsr->requester_name ?: 'Pemohon' }},</p>

    <p>
        Permohonan Anda dengan nomor <strong>{{ $dsr->request_id }}</strong>
        telah kami tindak lanjuti.
    </p>

    <p style="white-space: pre-line;">{{ $bodyText }}</p>

    <table cellpadding="6" style="border-collapse:collapse; margin:16px 0; font-size:14px;">
        <tr>
            <td style="color:#6b7280;">Nomor permohonan</td>
            <td><strong>{{ $dsr->request_id }}</strong></td>
        </tr>
        <tr>
            <td style="color:#6b7280;">Jenis permohonan</td>
            <td>{{ $dsr->request_type }}</td>
        </tr>
        <tr>
            <td style="color:#6b7280;">Status</td>
            <td>{{ $dsr->status }}</td>
        </tr>
    </table>

    <p style="font-size:13px; color:#6b7280;">
        Rincian lengkap terlampir dalam berkas PDF. Surel ini dikirim otomatis;
        balas surel ini bila Anda memerlukan penjelasan lebih lanjut.
    </p>

    @if ($orgName)
        <p style="font-size:13px; color:#6b7280;">Hormat kami,<br>{{ $orgName }}</p>
    @endif
</body>
</html>
