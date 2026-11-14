<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasUuids;

    public const CHANNELS = ['sms', 'email', 'push'];

    public const PRIORITIES = ['high', 'normal', 'low'];

    public const STATUSES = ['pending', 'processing', 'retrying', 'accepted', 'failed', 'uncertain', 'cancelled'];

    public const DELIVERY_STATUSES = ['awaiting_receipt', 'sent', 'delivered', 'undelivered'];

    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'content', 'processing_started_at', 'webhook_provider_identity'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'retry_generation' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'delivery_updated_at' => 'immutable_datetime',
        ];
    }
}
