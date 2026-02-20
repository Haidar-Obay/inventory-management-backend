<?php

namespace App\Enums;

enum CashAccountType: string
{
    case PHYSICAL_CASH = 'physical_cash';
    case BANK = 'bank';
    case WALLET = 'wallet';
    case CARD = 'card';
}
