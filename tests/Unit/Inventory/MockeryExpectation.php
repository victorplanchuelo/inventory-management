<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use Mockery\CompositeExpectation;

final class MockeryExpectation
{
	public function __construct(private readonly CompositeExpectation $expectation) {}

	public function once(): self
	{
		$this->expectation->__call('once', []);

		return $this;
	}

	public function with(mixed ...$arguments): self
	{
		$this->expectation->__call('with', $arguments);

		return $this;
	}

	public function withNoArgs(): self
	{
		$this->expectation->__call('withNoArgs', []);

		return $this;
	}

	public function andReturn(mixed ...$values): self
	{
		$this->expectation->__call('andReturn', $values);

		return $this;
	}

	public function andReturnNull(): self
	{
		$this->expectation->__call('andReturnNull', []);

		return $this;
	}
}
