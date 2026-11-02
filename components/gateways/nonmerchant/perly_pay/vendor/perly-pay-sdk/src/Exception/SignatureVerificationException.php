<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** A webhook whose Perly-Signature does not check out. */
final class SignatureVerificationException extends PerlyPayException
{
}
