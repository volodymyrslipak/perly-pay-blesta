<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** A field is wrong, missing or not allowed; getParam() names it. */
final class InvalidRequestException extends PerlyPayException
{
}
