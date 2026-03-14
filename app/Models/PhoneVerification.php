<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PhoneVerification extends Model
{
    use HasFactory, softDeletes;

    protected $fillable = [
        'phone_number',
        'pin_id',
        'otp',
        'verified',
        'expires_at',];
    protected $casts = [
        'verified' => 'boolean',
        'expires_at' => 'datetime',
    ];
}
