<?php

declare(strict_types=1);

namespace Manager\Infrastructure\User\Persistence;

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
	protected $fillable = ['name', 'email', 'password',];
	protected $hidden = ['password', 'remember_token', ];
	protected function casts(): array
	{
		return [
			'email_verified_at' => 'datetime',
            'password' => 'hashed',
		];
	}

	protected static function newFactory(): Factory
	{
		return UserFactory::new();
	}
}
