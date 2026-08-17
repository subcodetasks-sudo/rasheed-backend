<?php

namespace Modules\User\app\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable, SoftDeletes;

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    use LogsActivity;

    protected $fillable = [
        'full_name',
        'user_name',
        'email',
        'phone',
        'password',
        'status',
        'last_login_at',
        'deleted_at',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['full_name', 'email', 'user_name', 'role_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('User');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            $user->uuid = (string) Str::uuid();
        });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->registerMediaConversions(function (?Media $media = null) {
                $this->addMediaConversion('thumb')
                    ->width(200)
                    ->height(200)
                    ->sharpen(10);
            });
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isBanned(): bool
    {
        return $this->status === 'banned';
    }

    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function getRouteKeyName()
    {
        return 'uuid';
    }

    protected static function newFactory()
    {
        return UserFactory::new();
    }
}
