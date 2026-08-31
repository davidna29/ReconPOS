<?php

namespace App\Enums;

enum TipePembayaran: string
{
    case CASH = 'cash';
    case TRANSFER = 'transfer';
}
