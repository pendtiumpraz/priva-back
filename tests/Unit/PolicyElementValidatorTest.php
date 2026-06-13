<?php

namespace Tests\Unit;

use App\Services\PolicyElementValidator;
use Tests\TestCase;

/**
 * Deterministic (non-AI) coverage check for the 15 mandatory UU PDP privacy
 * policy elements. Scans the generated sections JSON for per-element markers.
 */
class PolicyElementValidatorTest extends TestCase
{
    /** A privacy policy whose sections touch all 15 mandatory elements. */
    private function fullSections(): array
    {
        return [
            ['type' => 'heading_1', 'text' => 'Kebijakan Privasi'],
            ['type' => 'paragraph', 'text' => 'PT Contoh selaku Pengendali Data Pribadi bertanggung jawab atas pemrosesan data Anda.'],
            ['type' => 'paragraph', 'text' => 'Narahubung DPO (Data Protection Officer) kami dapat dihubungi di dpo@contoh.id.'],
            ['type' => 'paragraph', 'text' => 'Kategori data yang dikumpulkan meliputi nama, email, dan nomor telepon.'],
            ['type' => 'paragraph', 'text' => 'Tujuan pemrosesan data adalah penyediaan layanan dan dukungan pelanggan.'],
            ['type' => 'paragraph', 'text' => 'Dasar hukum pemrosesan mengacu pada Pasal 20 UU PDP yaitu persetujuan dan pelaksanaan kontrak.'],
            ['type' => 'paragraph', 'text' => 'Masa retensi data: kami menyimpan data selama 5 tahun.'],
            ['type' => 'paragraph', 'text' => 'Kami dapat berbagi data dengan pihak ketiga seperti penyedia pembayaran.'],
            ['type' => 'paragraph', 'text' => 'Hak subjek data Anda mencakup hak akses, koreksi, dan penghapusan.'],
            ['type' => 'paragraph', 'text' => 'Anda dapat melakukan penarikan persetujuan kapan saja melalui pusat preferensi.'],
            ['type' => 'paragraph', 'text' => 'Langkah keamanan data kami meliputi enkripsi dan kontrol akses.'],
            ['type' => 'paragraph', 'text' => 'Kebijakan cookie: kami menggunakan kuki untuk analitik.'],
            ['type' => 'paragraph', 'text' => 'Data anak di bawah umur memerlukan persetujuan orang tua sesuai Permenkominfo 20/2016.'],
            ['type' => 'paragraph', 'text' => 'Transfer data lintas negara dilakukan dengan safeguard sesuai Pasal 56.'],
            ['type' => 'paragraph', 'text' => 'Pemberitahuan pelanggaran data (breach) disampaikan sesuai Pasal 46.'],
            ['type' => 'list', 'items' => ['Perubahan kebijakan ini akan diberitahukan dan versi kebijakan diperbarui secara berkala.']],
        ];
    }

    public function test_full_policy_covers_all_fifteen_elements(): void
    {
        $result = PolicyElementValidator::validate($this->fullSections());

        $this->assertSame(15, $result['total']);
        $this->assertSame(15, $result['covered_count'], 'Uncovered: '.implode(', ', $result['missing']));
        $this->assertTrue($result['all_covered']);
        $this->assertSame([], $result['missing']);
    }

    public function test_detects_missing_elements(): void
    {
        $sections = [
            ['type' => 'paragraph', 'text' => 'Kebijakan privasi ringkas tanpa banyak detail tentang apa pun.'],
        ];

        $result = PolicyElementValidator::validate($sections);

        $this->assertFalse($result['all_covered']);
        $this->assertContains('kontak_dpo', $result['missing']);
        $this->assertContains('data_anak', $result['missing']);
        $this->assertContains('cross_border', $result['missing']);
        $this->assertLessThan(15, $result['covered_count']);
    }

    public function test_reports_label_and_pasal_per_element(): void
    {
        $result = PolicyElementValidator::validate($this->fullSections());
        $byKey = collect($result['elements'])->keyBy('key');

        $this->assertCount(15, $result['elements']);
        $this->assertSame('Pasal 53', $byKey['kontak_dpo']['pasal']);
        $this->assertSame('Pasal 56', $byKey['cross_border']['pasal']);
        $this->assertSame('Pasal 46', $byKey['breach_notification']['pasal']);
        $this->assertTrue($byKey['kontak_dpo']['covered']);
    }

    public function test_empty_sections_cover_nothing(): void
    {
        $result = PolicyElementValidator::validate([]);

        $this->assertSame(0, $result['covered_count']);
        $this->assertFalse($result['all_covered']);
        $this->assertCount(15, $result['missing']);
    }

    public function test_customer_audience_applies_all_fifteen_elements(): void
    {
        $result = PolicyElementValidator::validate([], 'customer');

        $this->assertSame(15, $result['total']);
        $this->assertSame([], $result['not_applicable']);
    }

    public function test_employee_audience_excludes_cookie_and_child_data(): void
    {
        $result = PolicyElementValidator::validate($this->fullSections(), 'employee');

        $this->assertSame(13, $result['total']);
        $this->assertContains('cookie', $result['not_applicable']);
        $this->assertContains('data_anak', $result['not_applicable']);
        // N/A elements must NOT be flagged missing even if absent.
        $this->assertNotContains('cookie', $result['missing']);
        $this->assertNotContains('data_anak', $result['missing']);
        // Each element row carries an applicable flag.
        $byKey = collect($result['elements'])->keyBy('key');
        $this->assertFalse($byKey['cookie']['applicable']);
        $this->assertTrue($byKey['hak_subjek']['applicable']);
    }

    public function test_job_applicant_audience_excludes_cookie_and_child_data(): void
    {
        $result = PolicyElementValidator::validate([], 'job_applicant');

        $this->assertSame(13, $result['total']);
        $this->assertContains('cookie', $result['not_applicable']);
        $this->assertContains('data_anak', $result['not_applicable']);
    }

    public function test_employee_policy_can_be_fully_covered_without_cookie_or_child_data(): void
    {
        // A draft covering the 13 employee-applicable elements (no cookie/child data).
        $sections = collect($this->fullSections())
            ->reject(fn ($n) => str_contains(mb_strtolower($n['text'] ?? ($n['items'][0] ?? '')), 'cookie')
                || str_contains(mb_strtolower($n['text'] ?? ''), 'data anak'))
            ->values()->all();

        $result = PolicyElementValidator::validate($sections, 'employee');

        $this->assertTrue($result['all_covered'], 'Uncovered: '.implode(', ', $result['missing']));
        $this->assertSame(13, $result['covered_count']);
    }

    public function test_english_policy_is_recognized(): void
    {
        $sections = [
            ['type' => 'paragraph', 'text' => 'PT Example is the data controller responsible for processing your personal data.'],
            ['type' => 'paragraph', 'text' => 'Our Data Protection Officer (DPO) can be contacted at dpo@example.com.'],
            ['type' => 'paragraph', 'text' => 'Categories of data we collect include name, email and phone number.'],
            ['type' => 'paragraph', 'text' => 'The purpose of processing is service delivery and customer support.'],
            ['type' => 'paragraph', 'text' => 'Our legal basis is performance of a contract.'],
            ['type' => 'paragraph', 'text' => 'Data retention: we keep your data for five years.'],
            ['type' => 'paragraph', 'text' => 'We share data with third parties such as payment providers.'],
            ['type' => 'paragraph', 'text' => 'Your rights include the right to access, rectify and erase your data.'],
            ['type' => 'paragraph', 'text' => 'You may withdraw consent at any time.'],
            ['type' => 'paragraph', 'text' => 'Security measures include encryption and access control.'],
            ['type' => 'paragraph', 'text' => 'Cookie policy: we use cookies for analytics.'],
            ['type' => 'paragraph', 'text' => "Children's data requires parental consent for minors under the age of 18."],
            ['type' => 'paragraph', 'text' => 'Cross-border transfer abroad is done with safeguards (international transfer).'],
            ['type' => 'paragraph', 'text' => 'Data breach notification will be provided as required.'],
            ['type' => 'paragraph', 'text' => 'Changes to this policy will be communicated and the version updated.'],
        ];

        $result = PolicyElementValidator::validate($sections, 'customer');

        $this->assertSame(15, $result['covered_count'], 'Uncovered (EN): '.implode(', ', $result['missing']));
        $this->assertTrue($result['all_covered']);
    }

    public function test_generic_non_privacy_text_does_not_falsely_cover_elements(): void
    {
        $sections = [
            ['type' => 'paragraph', 'text' => 'Kami menjual sepatu dan mengirim pesanan dalam tiga hari kerja ke seluruh kota.'],
        ];

        $result = PolicyElementValidator::validate($sections);
        $byKey = collect($result['elements'])->keyBy('key');

        $this->assertFalse($byKey['hak_subjek']['covered']);
        $this->assertFalse($byKey['data_anak']['covered']);
        $this->assertFalse($byKey['kontak_dpo']['covered']);
        $this->assertFalse($byKey['cross_border']['covered']);
    }
}
