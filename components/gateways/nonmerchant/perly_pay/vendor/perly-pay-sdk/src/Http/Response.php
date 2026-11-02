<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Http;

if (!defined('DS')) {
    exit;
}

/** One HTTP answer: its status, its headers (names in lower case) and its raw body. */
final class Response
{
    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
