<?php

declare(strict_types=1);

namespace PerlyPay\Blesta\Vendor\Perly\Pay\Resource;

if (!defined('DS')) {
    exit;
}

use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Creates;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Deletes;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Lists;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Retrieves;
use PerlyPay\Blesta\Vendor\Perly\Pay\Resource\Concerns\Updates;

/** `/webhook_endpoints`: where events are POSTed, signed. */
final class WebhookEndpoints extends AbstractResource
{
    use Creates, Deletes, Lists, Retrieves, Updates;

    /**
     * A new signing secret; the old one stops at once.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> the endpoint, with its new `secret`
     */
    public function rollSecret(string $id, array $options = []): array
    {
        return $this->action($id, 'roll_secret', [], $options);
    }

    /**
     * Takes this endpoint's delivery of an event once: a second claim is refused with
     * 409 `already_claimed`, an event never sent to this endpoint with 404 `not_delivered`.
     *
     * @param array{event: string} $params
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> the event
     */
    public function claim(string $id, array $params, array $options = []): array
    {
        return $this->action($id, 'claim', $params, $options);
    }
}
