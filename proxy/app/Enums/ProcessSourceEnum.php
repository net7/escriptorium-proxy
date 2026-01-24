<?php

namespace App\Enums;

enum ProcessSourceEnum: string
{
    case Manifest = 'manifest';
    case Images = 'images';
}
