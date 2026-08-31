<?php

namespace App\Enums;

enum StatusBarang: string
{
    case DIJUAL = 'Dijual';
    case UNTUK_SERVIS = 'Untuk Servis';
    case DIJUAL_DAN_SERVIS = 'Dijual & Untuk Servis';
}
