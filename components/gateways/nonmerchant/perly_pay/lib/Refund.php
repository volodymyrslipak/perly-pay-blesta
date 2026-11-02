<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\AuthenticationException;
use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\ConnectionException;
use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\Blesta\Vendor\Perly\Pay\PerlyPay;

if (!defined('DS')) {
    exit;
}

/**
 * A refund from Blesta (refund(), when staff refund a Perly Pay
 * transaction) opens one in Perly Pay, in the coin that was paid: all of
 * what is left, or for part of the payment the same share of the coins.
 * Perly Pay holds no money: it emails the client for an address and checks
 * it, and the merchant sends from their own wallet and pastes the
 * transaction id in Perly Pay. So "refunded" here means the refund is open,
 * which the transaction's message says in words.
 *
 * Blesta edits the transaction with what refund() returns (status
 * "refunded", the amount refunded), so the transaction id stays the Perly
 * Pay invoice's: a webhook that comes later still finds the payment and
 * does not hand it over again.
 */
final class Refund
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
        private readonly Blesta $blesta,
    ) {
    }

    /**
     * @param mixed $amount the amount to refund, in the transaction's currency
     * @return array{ok: true, record: array<string, mixed>}|array{ok: false, error: string}
     */
    public function handle(?string $referenceId, ?string $transactionId, mixed $amount, ?string $currency): array
    {
        if ($this->client === null) {
            return self::fail('Add your Perly Pay secret key in the gateway settings first.');
        }
        $transactionId = trim((string) $transactionId);
        $row = $transactionId !== '' ? $this->store->byPayInvoice($transactionId) : null;
        if ($row === null || $row['company_id'] !== $this->settings->companyId()) {
            return self::fail('This transaction was not made by Perly Pay for this company, so there is nothing to refund there.');
        }
        if ($row['mode'] !== $this->client->mode) {
            return self::fail('This payment was made in ' . $row['mode'] . ' mode; the saved key is a ' . $this->client->mode . ' key.');
        }
        $part = Money::toMinor(Money::normalise($amount) ?? '');
        if ($part === null || $part <= 0) {
            return self::fail('Enter an amount to refund.');
        }
        $currency = strtoupper((string) ($currency ?: $row['currency']));
        if ($currency !== $row['currency']) {
            return self::fail('Refunds are taken in ' . $row['currency'] . ', the currency of this payment.');
        }
        $total = $row['amount'];
        if ($part > $total) {
            return self::fail('That is more than was paid with Perly Pay (' . Money::fromMinor($total) . ' ' . $currency . ').');
        }

        $request = ['invoice' => $row['pay_invoice'], 'reason' => 'Refund from Blesta (' . ($row['invoices'] === [] ? 'account credit' : 'invoice ' . implode(', ', array_map(static fn (array $l): string => '#' . $l['id'], $row['invoices']))) . ')'];
        try {
            if ($part < $total) {
                $invoice = $this->client->invoices->retrieve($row['pay_invoice']);
                $units = Money::share(is_string($invoice['amount_received'] ?? null) ? $invoice['amount_received'] : '', $part, $total);
                if ($units === null || $units === '0') {
                    return self::fail('That amount is too small to refund.');
                }
                $request['amount'] = $units;
            }
            $key = 'blesta_refund_' . substr(hash('sha256', $this->settings->callbackUrl() . '|' . $row['pay_invoice'] . '|' . count($row['refunds']) . '|' . $part), 0, 40);
            $refund = $this->client->refunds->create($request, ['idempotency_key' => $key]);
        } catch (PerlyPayException $error) {
            $this->blesta->log(Settings::apiBase() . '/api/v1/refunds', ['action' => 'open refund', 'invoice' => $row['pay_invoice'], 'code' => $error->getErrorCode(), 'request' => (string) $error->getRequestId()], 'output', false);

            return self::fail(self::explain($error));
        }

        $id = is_string($refund['id'] ?? null) ? $refund['id'] : '';
        if ($id === '') {
            return self::fail('Perly Pay answered without a refund.');
        }
        $this->store->addRefund($row['pay_invoice'], $id);
        $coins = isset($request['amount'])
            ? Money::units($request['amount'], is_int($refund['decimals'] ?? null) ? $refund['decimals'] : 0) . ' ' . Chains::asset((string) ($refund['asset'] ?? ''), (string) ($refund['chain'] ?? ''))
            : 'all that is left';
        $this->blesta->log(Settings::apiBase() . '/api/v1/refunds', [
            'result' => 'Refund opened',
            'refund' => $id,
            'status' => is_string($refund['status'] ?? null) ? $refund['status'] : '',
            'invoice' => $row['pay_invoice'],
            'amount' => Money::fromMinor($part) . ' ' . $currency,
            'coins' => $coins,
        ], 'output', true);

        return [
            'ok' => true,
            'record' => [
                'status' => 'refunded',
                'reference_id' => $referenceId,
                'transaction_id' => $row['pay_invoice'],
                'message' => Html::cut('Refund ' . $id . ' opened in Perly Pay for ' . $coins . '. The client gets an email to give an address; once it is checked, send the amount from your wallet and paste the transaction id in Perly Pay.', 255),
            ],
        ];
    }

    private static function explain(PerlyPayException $error): string
    {
        return match (true) {
            $error->getErrorCode() === 'sanctioned_sender' => 'Money on this invoice came from an address on a sanctions list, or one its issuer froze. It cannot be refunded.',
            $error instanceof AuthenticationException => 'Perly Pay refused the secret key. Check it in the gateway settings.',
            $error instanceof ConnectionException => 'Could not reach Perly Pay. Try again in a minute.',
            default => 'Perly Pay: ' . $error->getMessage() . ' (request ' . $error->getRequestId() . ')',
        };
    }

    /** @return array{ok: false, error: string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
