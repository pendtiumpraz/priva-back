<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Validator file upload yang lebih ketat daripada Laravel `mimes:` rule.
 *
 * Laravel default `mimes:pdf,docx` hanya cek client-side extension dari
 * filename. Attacker bisa rename `evil.php` → `evil.pdf` dan tetap lolos.
 *
 * Validator ini:
 *   1. Baca magic bytes / MIME real via `finfo` (PHP built-in)
 *   2. Cross-check extension client vs real MIME — reject kalau mismatch
 *   3. Whitelist MIME yang allowed per use case (document / image / archive)
 *   4. Block dangerous extension absolut (.php, .phtml, .sh, .exe, dll)
 *
 * Pakai pattern ini di SEMUA endpoint upload — jangan andalkan Laravel
 * rule saja.
 */
class FileUploadValidator
{
    /** MIME yang valid untuk dokumen (PDF, Office, text). */
    public const PRESET_DOCUMENT = 'document';
    /** MIME untuk image. */
    public const PRESET_IMAGE = 'image';
    /** MIME untuk arsip. */
    public const PRESET_ARCHIVE = 'archive';
    /** Dokumen + arsip (mis. pentest report bisa PDF atau ZIP). */
    public const PRESET_DOCUMENT_OR_ARCHIVE = 'document_or_archive';
    /** Chat attachment — dokumen + image. */
    public const PRESET_CHAT_ATTACHMENT = 'chat_attachment';

    private const ALLOWED_MIMES = [
        self::PRESET_DOCUMENT => [
            'application/pdf' => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'text/csv' => ['csv', 'txt'],
            'text/plain' => ['txt', 'csv'],
        ],
        self::PRESET_IMAGE => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
        ],
        self::PRESET_ARCHIVE => [
            'application/zip' => ['zip'],
            'application/x-zip-compressed' => ['zip'],
        ],
    ];

    /** Extension yang ABSOLUT diblokir — gak pernah upload, period. */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht',
        'sh', 'bash', 'zsh', 'csh', 'ksh',
        'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'ps1', 'psm1',
        'jar', 'war', 'ear',
        'html', 'htm', 'xhtml', 'svg', // SVG bisa berisi JS — block kalau bukan whitelist explicit
        'js', 'mjs', 'ts',
        'pl', 'py', 'rb',
        'htaccess', 'htpasswd', 'cgi',
    ];

    /**
     * Validate uploaded file. Throw RuntimeException kalau gagal — caller
     * tangkap dan return error response yang sesuai.
     *
     * @param UploadedFile $file
     * @param string $preset preset MIME yang allowed (PRESET_*)
     * @param array<int,string>|null $additionalAllowed extra extension allowed (override block list)
     */
    public function validate(UploadedFile $file, string $preset, ?array $additionalAllowed = null): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('File upload tidak valid.');
        }

        $ext = strtolower($file->getClientOriginalExtension());

        // 1. Block dangerous extension absolut
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
            // Allow override via additional list (mis. preview HTML)
            if (! $additionalAllowed || ! in_array($ext, $additionalAllowed, true)) {
                throw new RuntimeException("Extension '.{$ext}' tidak diizinkan.");
            }
        }

        // 2. Baca real MIME via finfo
        $realMime = $this->detectMime($file->getRealPath());
        if (! $realMime) {
            throw new RuntimeException('Tidak dapat membaca MIME type file.');
        }

        // 3. Resolve allowed MIME map dari preset
        $allowedMap = $this->presetMap($preset);

        // 4. Cek real MIME ada di whitelist
        if (! isset($allowedMap[$realMime])) {
            throw new RuntimeException(
                "Tipe file tidak diizinkan. Detected MIME: {$realMime}. "
                ."Format yang diperbolehkan: " . implode(', ', array_unique(array_merge(...array_values($allowedMap)))) . '.'
            );
        }

        // 5. Cross-check: extension client harus konsisten dengan real MIME
        $validExtForMime = $allowedMap[$realMime];
        if (! in_array($ext, $validExtForMime, true)) {
            throw new RuntimeException(
                "Mismatch extension dan MIME real. File mengaku '.{$ext}' tapi "
                ."isinya {$realMime}. Kemungkinan file di-rename / dimanipulasi."
            );
        }

        // 6. Sanity check size — kalau 0 byte, suspicious
        if ($file->getSize() <= 0) {
            throw new RuntimeException('File kosong (0 bytes).');
        }
    }

    /**
     * Detect MIME via finfo. Bukan trust client header Content-Type yang
     * mudah di-spoof.
     */
    private function detectMime(string $path): ?string
    {
        if (! function_exists('finfo_open')) {
            // Fallback: PHP built-in mime_content_type
            $mime = @mime_content_type($path);
            return $mime !== false ? $mime : null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (! $finfo) return null;
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime !== false ? $mime : null;
    }

    /**
     * @return array<string,array<int,string>> MIME → list of allowed extensions
     */
    private function presetMap(string $preset): array
    {
        return match ($preset) {
            self::PRESET_DOCUMENT => self::ALLOWED_MIMES[self::PRESET_DOCUMENT],
            self::PRESET_IMAGE => self::ALLOWED_MIMES[self::PRESET_IMAGE],
            self::PRESET_ARCHIVE => self::ALLOWED_MIMES[self::PRESET_ARCHIVE],
            self::PRESET_DOCUMENT_OR_ARCHIVE => array_merge(
                self::ALLOWED_MIMES[self::PRESET_DOCUMENT],
                self::ALLOWED_MIMES[self::PRESET_ARCHIVE],
            ),
            self::PRESET_CHAT_ATTACHMENT => array_merge(
                self::ALLOWED_MIMES[self::PRESET_DOCUMENT],
                self::ALLOWED_MIMES[self::PRESET_IMAGE],
            ),
            default => throw new RuntimeException("Unknown file upload preset: {$preset}"),
        };
    }
}
