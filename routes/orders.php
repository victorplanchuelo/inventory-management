<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Manager\Orders\Infrastructure\Api\Controllers\GetInventoryAvailabilityController;

Route::get('/inventory-availability/{productId}', GetInventoryAvailabilityController::class)
	->whereUuid('productId');
