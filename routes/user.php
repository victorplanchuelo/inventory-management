<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Manager\Api\User\Infrastructure\Create\PostCreateUserController;
use Manager\Api\User\Infrastructure\Index\GetAllUsersController;

Route::get('/', GetAllUsersController::class);
Route::post('/', PostCreateUserController::class);
