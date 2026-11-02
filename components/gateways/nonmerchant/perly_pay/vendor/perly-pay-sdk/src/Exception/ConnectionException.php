<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** The request never got an answer: network down, DNS, timeout. */
final class ConnectionException extends PerlyPayException
{
}
