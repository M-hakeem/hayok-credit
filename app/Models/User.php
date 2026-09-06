<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\Encrypted;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organisation_id',
        'fullname',
        'dob',
        'gender',
        'email',
        'residential_address',
        'state',
        'lga',
        'nin',
        'bvn',
        'phone_number',
        'profile_image',
        'phone_verified_at',
        'password',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'bank_code',
        'bank_connected_at',
        'status',
        'kyc_status',
        'account_level',
        'is_blacklisted',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'bank_account_number',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'bank_connected_at' => 'datetime',
            'is_blacklisted' => 'boolean',
            'password' => 'hashed',
            'nin' => Encrypted::class,
            'bvn' => Encrypted::class,
            'bank_account_number' => Encrypted::class,
        ];
    }

    public function organisation()
    {
        return $this->belongsTo(Organisation::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get the wallet for the user.
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get all withdrawal requests for the user.
     */
    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * Get all addresses for the user.
     */
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get all employments for the user.
     */
    public function employments()
    {
        return $this->hasMany(Employment::class);
    }

    /**
     * Get all guarantors for the user.
     */
    public function guarantors()
    {
        return $this->hasMany(Guarantor::class);
    }

    /**
     * Get all loans for the user.
     */
    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function paymentAuthorizations()
    {
        return $this->hasMany(PaymentAuthorization::class);
    }
}
