<?php

declare(strict_types=1);

namespace Tests\Acceptance\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Shared\TestCase;

final class PostCreateUserControllerTest extends TestCase
{
	use RefreshDatabase;

	public function test_the_application_returns_201_after_create_a_user(): void
	{
		$response = $this->post('/user', ['name' => 'John Doe', 'email' => 'john@doe.com', 'password' => 'password']);
		$response->assertStatus(201);
	}
}
