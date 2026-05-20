<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organisation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'api_key',
        'status',
        'notes',
    ];

    protected $hidden = [
        'api_key',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function loans()
    {
        return $this->hasManyThrough(Loan::class, User::class);
    }

    /**
     * Generate a new plain API key, store its hash, return the plain key.
     * The plain key is shown once and never stored.
     */
    public static function generateApiKey(): array
    {
        $plain = Str::random(64);
        $hash  = hash('sha256', $plain);

        return ['plain' => $plain, 'hash' => $hash];
    }

    public static function findByApiKey(string $plainKey): ?self
    {
        return self::where('api_key', hash('sha256', $plainKey))
            ->where('status', 'active')
            ->first();
    }
}
