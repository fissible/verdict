<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Storefront\StorefrontDemoController;

Route::get('/', StorefrontDemoController::class)->name('verdict.demo');
