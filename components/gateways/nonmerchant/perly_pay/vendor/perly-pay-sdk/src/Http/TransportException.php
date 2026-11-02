<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Http;

if (!defined('DS')) {
    exit;
}

/** The request got no answer at all. The client retries it, then raises a ConnectionException. */
final class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
