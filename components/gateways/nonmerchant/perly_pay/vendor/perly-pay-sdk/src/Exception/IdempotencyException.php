<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** The same Idempotency-Key was sent with a different request. */
final class IdempotencyException extends PerlyPayException
{
}
