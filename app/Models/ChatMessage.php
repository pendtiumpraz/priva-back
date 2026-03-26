<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ChatMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'conversation_id', 'role', 'content', 'sender_name',
    ];

    public function conversation()
    {
        return $this->belongsTo(ChatConversation::class);
    }
}
