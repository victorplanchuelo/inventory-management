<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Manager\Infrastructure\User\Create\PostCreateUserController;
use Manager\Infrastructure\User\Index\GetAllUsersController;

Route::get('/', GetAllUsersController::class);
Route::post('/', PostCreateUserController::class);
