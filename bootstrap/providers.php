<?php

declare(strict_types=1);

return [
	App\Providers\AppServiceProvider::class,
	App\Providers\InventoryServiceProvider::class,
	App\Providers\InboxServiceProvider::class,
	App\Providers\OutboxServiceProvider::class,
	App\Providers\RabbitMqProvider::class,
	App\Providers\UserProvider::class,
];
