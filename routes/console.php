<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
	$this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('manager:domain-events:mysql:consume', ['quantity' => 10])
    ->description('Consume MySql Domain Events from table')
    ->hourly();
