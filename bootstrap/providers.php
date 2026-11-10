<?php

declare(strict_types=1);

return [
	App\Providers\AppServiceProvider::class,
	App\Providers\OutboxServiceProvider::class,
	App\Providers\RabbitMqProvider::class,
	App\Providers\UserProvider::class,
];
