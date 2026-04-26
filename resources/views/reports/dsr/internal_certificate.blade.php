@extends('reports.dsr._layout')
@section('title', 'Bukti Internal Penyelesaian DSR')
@section('doc-id', $dsr->request_id)

@section('content')
<div class="cert-frame">
    <p class="cert-title">BUKTI INTERNAL PENYELESAIAN DSR</p>
    <p class="cert-sub">Confidential — Untuk arsip DPO {{ $orgName }}</p>

    <h3>Identifikasi Pemohon (PII Lengkap)</h3>
    <div class="field-row"><span class="field-label">Nomor Permintaan</span>: <strong>{{ $dsr->request_id }}</strong></div>
    <div class="field-row"><span class="field-label">Email Pemohon</span>: {{ $dsr->requester_email }}</div>
    <div class="field-row"><span class="field-label">Nama Pemohon</span>: {{ $dsr->requester_name ?: '—' }}</div>
    <div class="field-row"><span class="field-label">Telepon</span>: {{ $dsr->requester_phone ?: '—' }}</div>
    <div class="field-row"><span class="field-label">Identitas Tambahan</span>: <pre style="background:#f8fafc; padding:6px; font-size:8.5pt;">{{ json_encode($dsr->subject_data ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></div>

    <h3>Detail Permintaan</h3>
    <div class="field-row"><span class="field-label">Jenis</span>: {{ $requestTypeLabel }}</div>
    <div class="field-row"><span class="field-label">Aplikasi Sumber</span>: {{ optional($dsr->app)->name ?: '—' }}</div>
    <div class="field-row"><span class="field-label">Diajukan</span>: {{ optional($dsr->created_at)->format('d F Y H:i') }} WIB</div>
    <div class="field-row"><span class="field-label">Diverifikasi</span>: {{ optional($dsr->verified_at)->format('d F Y H:i') }} ({{ $dsr->verification_method ?: 'email_otp' }})</div>
    <div class="field-row"><span class="field-label">Deadline (UU PDP)</span>: {{ optional($dsr->deadline_at)->format('d F Y H:i') }} WIB</div>
    <div class="field-row"><span class="field-label">Diselesaikan</span>: {{ optional($dsr->closed_at ?? now())->format('d F Y H:i') }} WIB</div>
    <div class="field-row"><span class="field-label">SLA terpenuhi</span>: {{ $slaStatus }}</div>

    @if(!empty($dsr->description))
    <h3>Deskripsi Permintaan</h3>
    <p style="text-align:justify; font-size:9.5pt;">{{ $dsr->description }}</p>
    @endif

    <h3>Log Eksekusi per Sistem &amp; Shard</h3>
    <table class="scope-table">
        <thead>
            <tr>
                <th>Sistem</th><th>Shard</th><th>Tindakan</th>
                <th>Status</th><th>Rows</th><th>Eksekutor</th><th>Waktu</th>
            </tr>
        </thead>
        <tbody>
            @foreach($executions as $e)
                <tr>
                    <td>{{ $e['system_name'] }}</td>
                    <td>{{ $e['shard_name'] ?: '—' }}</td>
                    <td>{{ $e['request_type'] }}</td>
                    <td>{{ $e['status'] }}</td>
                    <td style="text-align:right;">{{ $e['rows_affected'] ?? '—' }}</td>
                    <td style="font-size:8.5pt;">{{ $e['executed_by_email'] ?: '—' }}</td>
                    <td style="font-size:8.5pt;">{{ $e['executed_at'] ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(!empty($evidenceList))
    <h3>Daftar Bukti Eksekusi</h3>
    <ul style="font-size:9pt;">
        @foreach($evidenceList as $ev)
            <li>{{ $ev['name'] }} — uploaded {{ $ev['uploaded_at'] }} ({{ $ev['size_kb'] }} KB)</li>
        @endforeach
    </ul>
    @endif

    @if(!empty($auditTrail))
    <h3>Audit Trail Singkat</h3>
    <table class="scope-table">
        <thead><tr><th>Waktu</th><th>Aktor</th><th>Tindakan</th></tr></thead>
        <tbody>
            @foreach($auditTrail as $row)
                <tr>
                    <td style="font-size:8.5pt;">{{ $row['ts'] }}</td>
                    <td style="font-size:8.5pt;">{{ $row['actor'] }}</td>
                    <td style="font-size:8.5pt;">{{ $row['action'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="signoff">
        <p>Disahkan oleh DPO,</p>
        <div class="sig-line"></div>
        <p><strong>{{ $dpoName ?? 'Data Protection Officer' }}</strong><br>{{ $orgName }}</p>
        <p class="stamp-id">SHA-256 stamp: {{ $verificationStamp }}</p>
    </div>
</div>
@endsection
