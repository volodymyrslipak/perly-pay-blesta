<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

if (!defined('DS')) {
    exit;
}

/**
 * The real Blesta: its Transactions and Clients models, loaded the way
 * gateways load them (Loader::loadModels), and the gateway's own log().
 *
 *   https://docs.blesta.com/developers/gateways/non-merchant-gateways
 */
final class BlestaFunctions implements Blesta
{
    /** @var \Transactions|null */
    public $Transactions;
    /** @var \Clients|null */
    public $Clients;

    /** @var \Closure(string, string, string, bool): void */
    private \Closure $logger;

    /** @param \Closure(string, string, string, bool): void $logger the gateway's log(), which is protected */
    public function __construct(\Closure $logger)
    {
        $this->logger = $logger;
    }

    public function transaction(string $transactionId, ?int $gatewayId): ?array
    {
        if ($transactionId === '') {
            return null;
        }
        if (!isset($this->Transactions)) {
            \Loader::loadModels($this, ['Transactions']);
        }
        $found = $this->Transactions->getByTransactionId($transactionId, null, $gatewayId);
        if (!is_object($found)) {
            return null;
        }

        return [
            'status' => (string) ($found->status ?? ''),
            'amount' => (string) ($found->amount ?? ''),
            'currency' => (string) ($found->currency ?? ''),
            'client_id' => (int) ($found->client_id ?? 0),
        ];
    }

    public function clientEmail(int $clientId): string
    {
        if ($clientId <= 0) {
            return '';
        }
        if (!isset($this->Clients)) {
            \Loader::loadModels($this, ['Clients']);
        }
        $client = $this->Clients->get($clientId, false);

        return is_object($client) && is_string($client->email ?? null) ? $client->email : '';
    }

    public function log(string $url, array $data, string $direction, bool $success): void
    {
        $text = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        try {
            ($this->logger)($url, is_string($text) ? $text : '', $direction, $success);
        } catch (\Throwable) {
            // A log line that cannot be written never stops a payment.
        }
    }
}
