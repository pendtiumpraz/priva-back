<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ChatMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'conversation_id', 'role', 'content', 'summary', 'sender_name',
        'attachment_url', 'attachment_name', 'attachment_type',
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'provider', 'model',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
    ];

    public function conversation()
    {
        return $this->belongsTo(ChatConversation::class);
    }
}
