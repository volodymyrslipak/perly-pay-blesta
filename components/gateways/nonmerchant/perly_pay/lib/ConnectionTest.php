<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\AuthenticationException;
use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\ConnectionException;
use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\PerlyPayException;

if (!defined('DS')) {
    exit;
}

/**
 * "Test connection" on the settings page: whether the saved key works,
 * its mode, and whether this company's callback is a webhook in Perly Pay
 * that sends invoice.fulfilled, with its secret saved here. It only
 * reads: nothing in Perly Pay or Blesta changes.
 */
final class ConnectionTest
{
    public static function run(Settings $settings): string
    {
        $client = $settings->client(0);
        if ($client === null) {
            return Lang::t('PerlyPay.test.no_key');
        }
        try {
            $client->invoices->list(['limit' => 1]);
            $found = null;
            foreach ($client->webhookEndpoints->listAll(['limit' => 100]) as $endpoint) {
                if (is_array($endpoint) && ($endpoint['url'] ?? '') === $settings->callbackUrl()) {
                    $found = $endpoint;
                    break;
                }
            }
        } catch (AuthenticationException) {
            return Lang::t('PerlyPay.test.refused');
        } catch (ConnectionException) {
            return Lang::t('PerlyPay.test.unreachable');
        } catch (PerlyPayException $error) {
            return Lang::t('PerlyPay.test.failed', $error->getMessage());
        }

        $mode = $client->mode === 'live' ? Lang::t('PerlyPay.test.live') : Lang::t('PerlyPay.test.test');
        if ($found === null) {
            return $mode . ' ' . Lang::t('PerlyPay.test.no_webhook');
        }
        $events = is_array($found['enabled_events'] ?? null) ? $found['enabled_events'] : [];
        if (($found['status'] ?? 'enabled') !== 'enabled') {
            return $mode . ' ' . Lang::t('PerlyPay.test.webhook_off');
        }
        if (!array_intersect(['*', 'invoice.*', 'invoice.fulfilled'], $events)) {
            return $mode . ' ' . Lang::t('PerlyPay.test.webhook_events');
        }
        if (!Keys::isWebhookSecret($settings->webhookSecret())) {
            return $mode . ' ' . Lang::t('PerlyPay.test.no_secret');
        }

        return $mode . ' ' . Lang::t('PerlyPay.test.ok');
    }
}
