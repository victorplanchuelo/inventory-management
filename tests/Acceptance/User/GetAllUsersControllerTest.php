<?php

declare(strict_types=1);

namespace Tests\Acceptance\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Shared\TestCase;

final class GetAllUsersControllerTest extends TestCase
{
	use RefreshDatabase;

	public function test_the_application_returns_201_after_create_a_user(): void
	{
		$response = $this->get('/user');
		$response->assertOk();
	}
}
