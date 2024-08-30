<?php

declare(strict_types=1);

namespace Manager\Infrastructure\User\Create;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Manager\Application\User\Creator\CreateUserCommand;
use Manager\Shared\ApiBaseController;
use Manager\Shared\Domain\Bus\Command\CommandBus;

final class PostCreateUserController extends ApiBaseController
{
	public function __construct(
		private readonly CommandBus $commandBus,
	) {}

	public function __invoke(Request $request): JsonResponse
	{
		$this->commandBus->dispatch(
			new CreateUserCommand($request->get('name'), $request->get('email'), $request->get('password'))
		);

		return new JsonResponse(
			null,
			201,
			['Access-Control-Allow-Origin' => '*']
		);
	}
}
