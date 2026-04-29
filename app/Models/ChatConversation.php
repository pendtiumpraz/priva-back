<?php
namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ChatConversation extends Model
{
    use HasUuids, BelongsToOrg;

    protected $fillable = [
        'user_id', 'org_id', 'user_name', 'user_email', 'status', 'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }
}
