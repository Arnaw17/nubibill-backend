<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncDeadLetterQueue extends Model
{
    protected $table = 'sync_dead_letter_queue';

    protected $fillable = [
        'tenant_id',
        'business_id',
        'collection',
        'document_id',
        'batch_id',
        'error_code',
        'error_message',
        'payload',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];
}
