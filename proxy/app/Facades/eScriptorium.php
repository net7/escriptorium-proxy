<?php

namespace App\Facades;

use App\Services\eScriptoriumService;
use Illuminate\Support\Facades\Facade;

class eScriptorium extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return eScriptoriumService::class;
    }
}
