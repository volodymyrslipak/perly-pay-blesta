<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns;

if (!defined('DS')) {
    exit;
}

/** @mixin \PerlyPay\Blesta\Vendor\Perly\Pay\Resource\AbstractResource */
trait Updates
{
    /**
     * @param array<string, mixed> $params only the fields to change
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return $this->requestor->request('POST', $this->idPath($id), $params, [], $options);
    }
}
