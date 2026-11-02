<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** The secret key is missing, malformed, revoked, or for the other mode. */
final class AuthenticationException extends PerlyPayException
{
}
