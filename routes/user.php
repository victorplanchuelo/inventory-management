<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Manager\Api\User\Infrastructure\Create\PostCreateUserController;
use Manager\Api\User\Infrastructure\Index\GetAllUsersController;
use Manager\Api\User\Infrastructure\Search\GetUsersByCriteriaController;

Route::get('/', GetAllUsersController::class);
Route::post('/', PostCreateUserController::class);
Route::get('/criteria', GetUsersByCriteriaController::class);
