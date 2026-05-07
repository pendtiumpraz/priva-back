<?php

namespace Tests\Feature\Lms;

use App\Lms\Services\CertificateSigningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateSigningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_signs_payload_and_verifies_round_trip(): void
    {
        $svc = app(CertificateSigningService::class);

        $payload = [
            'certificate_number' => 'CERT-TEST-001',
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'org_id' => '00000000-0000-0000-0000-000000000002',
            'course_id' => 1,
            'issued_at' => '2026-05-07T00:00:00Z',
        ];

        $signed = $svc->sign($payload);
        $this->assertNotEmpty($signed);
        $this->assertTrue($svc->verify($signed));
    }

    public function test_tampered_payload_fails_verification(): void
    {
        $svc = app(CertificateSigningService::class);
        $signed = $svc->sign(['x' => 1]);
        $tampered = preg_replace('/x":1/', 'x":2', $signed) ?? $signed;
        $this->assertFalse($svc->verify($tampered));
    }
}
