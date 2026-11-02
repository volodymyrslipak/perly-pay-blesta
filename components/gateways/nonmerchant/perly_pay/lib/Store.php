<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

if (!defined('DS')) {
    exit;
}

/**
 * Which Perly Pay invoice was made for which Blesta payment, and what came
 * of it. One row per Perly Pay invoice:
 *
 *   pay_invoice     inv_…, unique; also the Blesta transaction id once paid
 *   company_id      the Blesta company whose gateway made it
 *   client_id       the Blesta client paying
 *   invoices_key    which invoices and amounts it pays, hashed: the same payment looks up the same rows
 *   invoices        the same as a list, [{"id": 1001, "amount": "24.50"}], applied when it is paid
 *   mode            test | live (the key's)
 *   amount          what Perly Pay bills, minor units, in `currency` (USD|EUR, the payment's own)
 *   url             the hosted invoice
 *   status          open | closed | paid
 *   claim, claimed_at   who is handing the payment to Blesta right now (one webhook at a time)
 *   events          the last event ids noted (a replay notes nothing twice)
 *   refunds         the refunds opened from Blesta
 *
 * @phpstan-type Row array{pay_invoice: string, company_id: int, client_id: int, invoices_key: string, invoices: list<array{id: int, amount: string}>, mode: string, amount: int, currency: string, url: string, status: string, claim: string, claimed_at: int, events: list<string>, refunds: list<string>}
 */
interface Store
{
    /** Seconds after which a claim that never finished may be taken over. */
    public const CLAIM_SECONDS = 600;

    /** @return array<string, mixed>|null the newest row for this payment in this mode */
    public function latestFor(int $companyId, int $clientId, string $invoicesKey, string $mode): ?array;

    /** @return array<string, mixed>|null the newest row of this client's, for the page they come back to */
    public function latestForClient(int $companyId, int $clientId): ?array;

    /** How many Perly Pay invoices were made for this payment, in any mode. */
    public function count(int $companyId, int $clientId, string $invoicesKey): int;

    /** @param array<string, mixed> $row */
    public function add(array $row): void;

    /** @return array<string, mixed>|null */
    public function byPayInvoice(string $payInvoice): ?array;

    public function setStatus(string $payInvoice, string $status): void;

    /**
     * Takes the row for handing its payment to Blesta: true only for the one
     * caller that finds it unclaimed (or claimed longer ago than CLAIM_SECONDS).
     */
    public function claim(string $payInvoice, string $token, int $now): bool;

    public function release(string $payInvoice, string $token): void;

    public function hasEvent(string $payInvoice, string $eventId): bool;

    public function rememberEvent(string $payInvoice, string $eventId): void;

    public function addRefund(string $payInvoice, string $refundId): void;
}
