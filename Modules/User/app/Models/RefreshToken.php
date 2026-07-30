<?php

namespace Modules\User\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// use Modules\User\Database\Factories\RefreshTokenFactory;

class RefreshToken extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['user_id', 'token', 'expires_at', 'is_revoked'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_revoked' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public static function createToken(User $user): string
    {
        $token = Str::random(64);

        static::create([
            'user_id' => $user->uuid,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
        ]);

        return $token;
    }

    public static function findValidToken(string $token): ?self
    {
        return static::where('token', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->where('is_revoked', false)
            ->first();
    }
}
