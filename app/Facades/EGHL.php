<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class EGHL extends Facade
{
    public static function getFacadeAccessor()
    {
        // 用 eGHL
        return 'payment.eghl';
    }
}