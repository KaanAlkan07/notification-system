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

    protected $guarded = ['id'];

    protected $hidden = ['recipient', 'content', 'processing_started_at'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
