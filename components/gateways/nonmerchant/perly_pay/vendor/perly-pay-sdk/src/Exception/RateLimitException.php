<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** Too many requests; already retried with backoff before it is raised. */
final class RateLimitException extends PerlyPayException
{
}
