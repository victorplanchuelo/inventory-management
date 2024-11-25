<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\ConfigureRabbitMqCommand;
use Manager\Shared\Infrastructure\Bus\Event\RabbitMq\ConsumeRabbitMqDomainEventsCommand;

return Application::configure(basePath: dirname(__DIR__))
	->withRouting(
		web: __DIR__ . '/../routes/web.php',
		commands: __DIR__ . '/../routes/console.php',
		health: '/up',
		then: function () {
			Route::prefix('user')
				->name('user.')
				->group(base_path('routes/user.php'));
		}
	)
    ->withCommands([
        ConfigureRabbitMqCommand::class,
        ConsumeRabbitMqDomainEventsCommand::class,
    ])
	->withMiddleware(function (Middleware $middleware) {
		//
	})
	->withExceptions(function (Exceptions $exceptions) {
		//
	})->create();
