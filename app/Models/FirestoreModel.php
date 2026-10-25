<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class FirestoreModel extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'is_deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
