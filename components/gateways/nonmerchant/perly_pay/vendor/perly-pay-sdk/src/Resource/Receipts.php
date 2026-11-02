<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Resource;

if (!defined('DS')) {
    exit;
}

use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Retrieves;

/** `/receipts`: one per paid invoice and per refund. */
final class Receipts extends AbstractResource
{
    use Lists, Retrieves;

    /**
     * The receipt as a PDF, its raw bytes.
     *
     * @param array<string, mixed> $options
     */
    public function pdf(string $id, array $options = []): string
    {
        return $this->requestor->raw($this->idPath($id, 'pdf'), $options);
    }
}
