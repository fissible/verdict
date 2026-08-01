<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn (): array => [
    'package' => 'fissible/verdict',
    'status' => 'workbench ready',
]);
