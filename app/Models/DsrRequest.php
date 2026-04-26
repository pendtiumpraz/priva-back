<?php
namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class DsrRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'app_id', 'request_id', 'request_type', 'requester_name', 'requester_email',
        'requester_phone', 'description', 'subject_data',
        'status', 'verification_status', 'verification_token', 'verification_expires_at',
        'verification_method', 'verified_at',
        'response', 'rejection_reason',
        'deadline_at', 'responded_at', 'closed_at', 'closed_reason',
        'assigned_to', 'created_by',
        'nda_signed_at', 'nda_signed_doc_id',
        'subject_certificate_doc_id', 'internal_certificate_doc_id',
        'completion_certificate_doc_id',
    ];

    protected $casts = [
        'deadline_at' => 'datetime',
        'responded_at' => 'datetime',
        'closed_at' => 'datetime',
        'verification_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'nda_signed_at' => 'datetime',
        'subject_data' => 'array',
        // PII Encryption — AES-256-CBC
        'requester_name' => EncryptedString::class,
        'requester_email' => EncryptedString::class,
        'requester_phone' => EncryptedString::class,
        'description' => EncryptedString::class,
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function app()
    {
        return $this->belongsTo(DsrApp::class, 'app_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopes()
    {
        return $this->hasMany(DsrRequestScope::class, 'dsr_request_id');
    }

    public function executions()
    {
        return $this->hasMany(DsrExecution::class, 'dsr_request_id');
    }

    public function ndaSignedDoc()
    {
        return $this->belongsTo(\App\Models\Document::class, 'nda_signed_doc_id');
    }

    public function subjectCertificate()
    {
        return $this->belongsTo(\App\Models\Document::class, 'subject_certificate_doc_id');
    }

    public function internalCertificate()
    {
        return $this->belongsTo(\App\Models\Document::class, 'internal_certificate_doc_id');
    }

    /**
     * Check if all executions are final (executed/skipped) — bisa close DSR.
     * Failed executions block completion (admin harus retry atau mark skipped explicit).
     */
    public function allExecutionsComplete(): bool
    {
        $executions = $this->executions()->get();
        if ($executions->isEmpty()) return false;
        return $executions->every(fn($e) => $e->countsAsComplete());
    }

    /**
     * Status flow:
     *   pending_verification → verified → pending_review → in_progress
     *   → pending_execution → completed | rejected | cancelled
     */
    public const VALID_STATUSES = [
        'pending_verification', 'verified', 'pending_review',
        'in_progress', 'pending_execution', 'completed',
        'rejected', 'cancelled',
        // Legacy statuses kept for backward-compat
        'new', 'new_reply', 'replied', 'closed',
    ];

    public const REQUEST_TYPES = [
        'access', 'correction', 'rectification', 'deletion', 'erasure',
        'portability', 'restriction', 'objection', 'withdraw_consent', 'info',
    ];
}
