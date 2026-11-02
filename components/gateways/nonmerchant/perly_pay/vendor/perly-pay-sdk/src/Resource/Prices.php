<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Resource;

if (!defined('DS')) {
    exit;
}

use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Creates;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Retrieves;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Updates;

/** `/prices`: how a product is sold (one-time, subscription, pay what you want, …). */
final class Prices extends AbstractResource
{
    use Creates, Lists, Retrieves, Updates;
}
