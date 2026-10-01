<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PhoneVerification extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'phone_number',
        'otp_hash',
        'verified',
        'expires_at',
        'delivery_channel',
        'delivery_status',
        'attempt_count',
        'message_id',
        'purpose',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'expires_at' => 'datetime',
        'attempt_count' => 'integer',
    ];
}
