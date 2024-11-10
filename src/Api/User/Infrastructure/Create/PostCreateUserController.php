<?php

declare(strict_types=1);

namespace Manager\Api\User\Infrastructure\Create;

use Illuminate\Http\JsonResponse;
use Manager\Api\User\Application\Creator\CreateUserCommand;
use Manager\Api\User\Application\Requests\CreateUserRequest;
use Manager\Shared\ApiBaseController;
use Manager\Shared\Domain\Bus\Command\CommandBus;

final class PostCreateUserController extends ApiBaseController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
    }

    public function __invoke(CreateUserRequest $request): JsonResponse
    {
        $this->commandBus->dispatch(
            new CreateUserCommand(
                $request->get('uuid'),
                $request->get('name'),
                $request->get('email'),
                $request->get('password')
            )
        );

        return new JsonResponse(
            null,
            201,
            ['Access-Control-Allow-Origin' => '*']
        );
    }
}
