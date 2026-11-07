<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
