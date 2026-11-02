<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** The key is good but may not do this. */
final class PermissionException extends PerlyPayException
{
}
