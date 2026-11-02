<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Exception;

if (!defined('DS')) {
    exit;
}

/** Any other error the API answered (a 5xx after its retries, an unknown type). */
final class ApiException extends PerlyPayException
{
}
