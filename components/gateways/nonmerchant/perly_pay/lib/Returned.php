<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

use PerlyPay\Blesta\Vendor\Perly\Pay\PerlyPay;

if (!defined('DS')) {
    exit;
}

/**
 * The client back from the payment page (success()), on Blesta's
 * "payment received" page: client/pay/received/perly_pay/?client_id=…
 * (pay.js and Perly Pay's page add &invoice=inv_…).
 *
 * Blesta only shows what success() returns (GatewayPayments::processReceived
 * records nothing), so this says how the payment stands: "approved" once
 * Perly Pay fulfilled it, "pending" while money has arrived and waits for
 * confirmations, the rest of it or the sender's screening, and nothing when
 * nothing was sent. The payment itself is recorded by the webhook alone.
 * Only an invoice this company's gateway made for this very client is
 * shown, and its figures come from this Blesta's own table, never from the
 * address.
 */
final class Returned
{
    /** Money has arrived and Perly Pay is waiting for confirmations, the rest of it, or its screening. */
    private const ON_THE_WAY = ['seen', 'underpaid', 'paid', 'overpaid', 'review'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
    ) {
    }

    /**
     * @param array<string, mixed> $get
     * @return array<string, mixed>|null
     */
    public function handle(array $get): ?array
    {
        $clientId = (int) ($get['client_id'] ?? 0);
        if ($clientId <= 0) {
            return null;
        }
        $asked = is_string($get['invoice'] ?? null) && preg_match('/^inv_[A-Za-z0-9]{1,60}$/', $get['invoice']) === 1 ? $get['invoice'] : null;
        $row = $asked !== null ? $this->store->byPayInvoice($asked) : $this->store->latestForClient($this->settings->companyId(), $clientId);
        if ($row === null || $row['client_id'] !== $clientId || $row['company_id'] !== $this->settings->companyId()) {
            return null;
        }
        $status = $row['status'] === 'paid' ? 'approved' : null;
        if ($status === null) {
            try {
                $invoice = $this->client?->invoices->retrieve($row['pay_invoice'], ['max_retries' => 1]) ?? [];
            } catch (\Throwable) {
                // Nothing to say, then: the webhook records the payment either way.
                return null;
            }
            $status = match (true) {
                !empty($invoice['fulfilled_at']) => 'approved',
                in_array($invoice['status'] ?? '', self::ON_THE_WAY, true) => 'pending',
                default => null,
            };
        }
        if ($status === null) {
            // Nothing sent (or closed unpaid): no payment to speak of.
            return null;
        }

        return [
            'client_id' => $clientId,
            'amount' => Money::fromMinor($row['amount']),
            'currency' => $row['currency'],
            'invoices' => $row['invoices'],
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $row['pay_invoice'],
            'parent_transaction_id' => null,
        ];
    }
}
