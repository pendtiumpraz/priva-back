<?php

namespace App\Services;

use App\Models\User;

/**
 * Password policy validator. Threshold dibaca dari config('security.password.*'),
 * yang di-hidrasi dari tabel system_settings. Default-nya konservatif tapi
 * bukan agresif — min 12 char, complexity (UULDS), block top-100 common
 * passwords, block password yang persis email/local-part.
 *
 * Dipanggil dari:
 *   - AuthController::register
 *   - UserController::store / ::update (admin set password user lain)
 *
 * Tidak dipanggil dari Sanctum / authentikasi (password lama sudah di-hash;
 * policy hanya berlaku saat password baru di-set).
 *
 * Output: list violation code + message readable. Frontend bisa pakai code
 * untuk live indicator (checklist "min 12 char ✓ uppercase ✓ ...").
 */
class PasswordPolicyService
{
    /**
     * Top common passwords yang harus diblokir kalau policy.block_common=true.
     * Disusun dari OWASP/SecLists — sengaja inline (gak load file) supaya cek
     * O(1) lewat array_flip + isset, tidak ada disk IO di hot path register.
     *
     * Disimpan di array_flip cache supaya isset() lookup, bukan in_array().
     */
    private const COMMON_PASSWORDS = [
        '123456','123456789','12345','qwerty','password','12345678','111111','123123','1234567890','1234567',
        'qwerty123','000000','1q2w3e','aa12345678','abc123','password1','1234','qwertyuiop','123321','password123',
        'iloveyou','admin','welcome','monkey','dragon','letmein','master','sunshine','princess','football',
        '666666','!@#$%^&*','charlie','aa123456','donald','password!','qazwsx','trustno1','jordan23','harley',
        'robert','matthew','jordan','asshole','daniel','andrew','lakers','andrea','buster','joshua',
        'pepper','andrew1','shadow','jennifer','hunter','michael','superman','batman','liverpool','soccer',
        'killer','baseball','michelle','password12','admin123','root','toor','pass','pass123','passwd',
        'changeme','default','guest','test','test123','demo','demo123','user','user123','login',
        '12341234','11111111','00000000','asdfgh','asdfghjkl','zxcvbnm','qweasd','qwer1234','q1w2e3r4','1qaz2wsx',
        'p@ssw0rd','p@ssword','password!1','password1!','admin@123','Admin@123','Admin123','administrator','manager','master123',
    ];

    public const CODE_LENGTH = 'length';
    public const CODE_UPPERCASE = 'uppercase';
    public const CODE_LOWERCASE = 'lowercase';
    public const CODE_DIGIT = 'digit';
    public const CODE_SYMBOL = 'symbol';
    public const CODE_COMMON = 'common';
    public const CODE_EMAIL_MATCH = 'email_match';

    /**
     * Validate password against active policy. Returns list of violations.
     * Empty array = valid.
     *
     * @param string $password Plain text password
     * @param string|null $email Email user (untuk block_email_match check)
     * @return list<array{code:string,message:string}>
     */
    public function validate(string $password, ?string $email = null): array
    {
        $cfg = $this->config();
        $violations = [];

        $minLength = (int) ($cfg['min_length'] ?? 12);
        if (mb_strlen($password) < $minLength) {
            $violations[] = [
                'code' => self::CODE_LENGTH,
                'message' => "Password minimal {$minLength} karakter.",
            ];
        }

        if (($cfg['require_uppercase'] ?? true) && ! preg_match('/[A-Z]/u', $password)) {
            $violations[] = [
                'code' => self::CODE_UPPERCASE,
                'message' => 'Password harus mengandung minimal 1 huruf besar.',
            ];
        }
        if (($cfg['require_lowercase'] ?? true) && ! preg_match('/[a-z]/u', $password)) {
            $violations[] = [
                'code' => self::CODE_LOWERCASE,
                'message' => 'Password harus mengandung minimal 1 huruf kecil.',
            ];
        }
        if (($cfg['require_digit'] ?? true) && ! preg_match('/\d/', $password)) {
            $violations[] = [
                'code' => self::CODE_DIGIT,
                'message' => 'Password harus mengandung minimal 1 angka.',
            ];
        }
        if (($cfg['require_symbol'] ?? true) && ! preg_match('/[^A-Za-z0-9]/u', $password)) {
            $violations[] = [
                'code' => self::CODE_SYMBOL,
                'message' => 'Password harus mengandung minimal 1 simbol (mis. !@#$%).',
            ];
        }

        if ($cfg['block_common'] ?? true) {
            $lower = mb_strtolower($password);
            $blockSet = array_flip(array_map('mb_strtolower', self::COMMON_PASSWORDS));
            if (isset($blockSet[$lower])) {
                $violations[] = [
                    'code' => self::CODE_COMMON,
                    'message' => 'Password ini termasuk yang paling umum digunakan — pilih yang lebih unik.',
                ];
            }
        }

        if (($cfg['block_email_match'] ?? true) && $email) {
            $emailLower = mb_strtolower($email);
            $local = strtok($emailLower, '@') ?: $emailLower;
            $pwLower = mb_strtolower($password);

            // Reject kalau password persis sama dengan email atau local-part
            // (e.g. user@x.com → password "user" atau "user@x.com" ditolak).
            // Length-bound: kalau local cuma 1-2 char (mis. "x@..."), gak fair
            // dianggap match. Minimal 4 char baru kena.
            if (mb_strlen($local) >= 4 && ($pwLower === $emailLower || $pwLower === $local)) {
                $violations[] = [
                    'code' => self::CODE_EMAIL_MATCH,
                    'message' => 'Password tidak boleh sama dengan email atau bagian sebelum @.',
                ];
            }
        }

        return $violations;
    }

    /**
     * Bentuk policy untuk dikirim ke frontend (register form, etc.) — supaya
     * UI bisa render checklist live. Tidak include common-passwords list
     * (300+ entries, dan bukan rahasia tapi gede).
     */
    public function getPolicy(): array
    {
        $cfg = $this->config();
        return [
            'min_length' => (int) ($cfg['min_length'] ?? 12),
            'require_uppercase' => (bool) ($cfg['require_uppercase'] ?? true),
            'require_lowercase' => (bool) ($cfg['require_lowercase'] ?? true),
            'require_digit' => (bool) ($cfg['require_digit'] ?? true),
            'require_symbol' => (bool) ($cfg['require_symbol'] ?? true),
            'block_common' => (bool) ($cfg['block_common'] ?? true),
            'block_email_match' => (bool) ($cfg['block_email_match'] ?? true),
        ];
    }

    private function config(): array
    {
        return (array) config('security.password', []);
    }
}
