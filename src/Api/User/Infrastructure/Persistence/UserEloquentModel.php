<?php

declare(strict_types=1);

namespace Manager\Api\User\Infrastructure\Persistence;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class UserEloquentModel extends Authenticatable
{
	use HasFactory, Notifiable;

	protected $table = 'users';
	protected $primaryKey = 'id';
	public $incrementing = true;
	public $timestamps = true;
	protected $fillable = ['uuid', 'name', 'email', 'password', ];
	protected $hidden = ['password', 'remember_token', ];


	protected static function newFactory(): Factory
	{
		return UserFactory::new();
	}

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
