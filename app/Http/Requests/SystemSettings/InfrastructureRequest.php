<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Hosting tier + driver selection (INFRASTRUCTURE_PLAN.md §7).
 *
 * Most fields are operational toggles. The `sqs_*` fields are conditional:
 * required only when queue_driver=sqs. They live in this section (not a
 * separate AWS section) because S3 storage is handled by the StoragePool
 * model — system_settings only tracks SQS credentials when the queue
 * subsystem actually needs them.
 */
class InfrastructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'hosting_tier' => 'required|string|in:shared,vps,aws',
            'queue_driver' => 'required|string|in:sync,database,redis,sqs',
            'cache_driver' => 'required|string|in:file,database,redis,memcached',
            'session_driver' => 'required|string|in:file,database,redis,cookie',

            // SQS — required only when queue_driver=sqs. `sqs_region` keeps a
            // default ('ap-southeast-1') so the field is always submittable.
            'sqs_access_key' => 'nullable|required_if:queue_driver,sqs|string|max:191',
            'sqs_secret_key' => 'nullable|required_if:queue_driver,sqs|string|max:1024',
            'sqs_region' => 'nullable|string|max:32',
            'sqs_queue_url' => 'nullable|required_if:queue_driver,sqs|string|url|max:255',
        ];
    }
}
