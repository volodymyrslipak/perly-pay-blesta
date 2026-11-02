<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

if (!defined('DS')) {
    exit;
}

/**
 * What the module asks of Blesta itself, behind one interface so its rules
 * can be tested without Blesta. BlestaFunctions is the real one.
 */
interface Blesta
{
    /**
     * The transaction Blesta recorded under this transaction id for this gateway
     * (Transactions::getByTransactionId), or null.
     *
     * @return array{status: string, amount: string, currency: string, client_id: int}|null
     */
    public function transaction(string $transactionId, ?int $gatewayId): ?array;

    /** The client's email (Clients::get), "" when unknown. */
    public function clientEmail(int $clientId): string;

    /**
     * A line in the gateway log (Tools → Logs → Gateway), through Gateway::log().
     *
     * @param array<string, mixed> $data
     */
    public function log(string $url, array $data, string $direction, bool $success): void;
}
