<?php

namespace App\Enums;

enum InvoiceType: string
{
    case PURCHASE = 'purchase';
    case SALE = 'sale';
}
