<?php

namespace App\Enums;

enum VoucherType: string
{
    case RECEIPT = 'receipt';
    case PAYMENT = 'payment';
}
