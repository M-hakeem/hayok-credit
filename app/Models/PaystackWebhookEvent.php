<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaystackWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_id', 'event', 'reference', 'payload', 'processed_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}