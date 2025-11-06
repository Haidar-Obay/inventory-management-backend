<?php

namespace App\Enums;

enum ItemType: string
{
    case INVENTORY = 'inventory';
    case NON_INVENTORY = 'non_inventory';
    case SERVICE = 'service';
    case BUNDLE = 'bundle';
    case MEDICAL_SERVICE = 'medical_service';
}
