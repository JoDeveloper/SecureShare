<?php

namespace Jodeveloper\SecureShare\Enums;

enum SecureShareStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case Burned = 'burned';
}
