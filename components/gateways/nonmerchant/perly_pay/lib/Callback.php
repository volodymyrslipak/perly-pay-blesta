<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\SignatureVerificationException;
use PerlyPay\Blesta\Vendor\Perly\Pay\PerlyPay;
use PerlyPay\Blesta\Vendor\Perly\Pay\Webhook;

if (!defined('DS')) {
    exit;
}

/**
 * What a Perly Pay event does in Blesta (validate(), behind Blesta's
 * callback https://your-blesta/callback/gw/<company>/perly_pay/).
 *
 * A request counts only with a good Perly-Signature (HMAC-SHA256 of the
 * timestamp and the raw body under the webhook's secret, at most five
 * minutes old, checked by the SDK in constant time); one that fails
 * changes nothing. Then, for a Perly Pay invoice this company's gateway made:
 *
 *   invoice.fulfilled (or paid/overpaid once fulfilled)  → the payment for Blesta to record: the client,
 *       the amount billed in the payment's own currency, status "approved", the invoices it pays, the
 *       Perly Pay invoice (inv_…) as the transaction id and its first transfer (pay_…) as the reference;
 *       logged with the network, the coin amount and a transaction link
 *   invoice.paid / overpaid before fulfilment   → logged: Perly Pay is screening the sender
 *   invoice.seen / underpaid / review           → logged, no payment
 *   invoice.expired / canceled                  → logged, no payment; the next visit makes a new invoice
 *   invoice.refunded, refund.*                  → logged
 *
 * A signed event is not taken at its word: the webhook secret alone is not
 * proof of payment. For invoice.* the invoice is retrieved from Perly Pay
 * with the gateway's API key and only that answer is used (status, mode,
 * fulfilment, amounts); an event it does not bear out (an "invoice.fulfilled"
 * for an invoice still open) is acknowledged and ignored. For refund.* the
 * refund is retrieved the same way, must be the one the event names and of
 * the invoice it names, and its own status, amounts, address and transaction
 * are what the log says: an address in a signed body is never one the admin
 * is told to send to.
 *
 * Blesta records what validate() returns (GatewayPayments::processNotification): it adds a transaction,
 * or edits the one with the same transaction id, applies it to the invoices when approved, and emails
 * the client each time. So the payment is handed over once: the Perly Pay invoice id is the
 * transaction id (the same on every delivery), a payment Blesta already has is not handed over again,
 * and the row is claimed first so two deliveries at the same moment cannot both hand it over. Each
 * other event is noted once. The gateway log gets ids, amounts, networks and links — never the body as
 * it came (it holds the client's email), a key or a signature.
 *
 * Answers (validate() sets the HTTP status; Blesta's callback itself always answers 200): 200 for
 * every verified event (handed over, a duplicate, or not this company's), 400 for a bad signature,
 * 503 until configured, while another delivery is handing the same payment over, or while the
 * invoice or the refund cannot be retrieved from Perly Pay — Perly Pay retries anything but 2xx for 72 hours.
 */
final class Callback
{
    public const EVENT_TYPES = [
        'invoice.seen',
        'invoice.underpaid',
        'invoice.paid',
        'invoice.overpaid',
        'invoice.review',
        'invoice.expired',
        'invoice.canceled',
        'invoice.fulfilled',
        'invoice.refunded',
        'refund.requested',
        'refund.address_received',
        'refund.sent',
        'refund.confirmed',
        'refund.canceled',
    ];

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly Settings $settings,
        private readonly Blesta $blesta,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
        private readonly ?int $gatewayId,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @return array{status: int, body: array<string, mixed>, record: array<string, mixed>|null}
     */
    public function handle(string $payload, ?string $signature): array
    {
        $secret = $this->settings->webhookSecret();
        if ($this->settings->mode() === null || $secret === '') {
            return self::answer(503, ['error' => 'not_configured']);
        }
        try {
            $event = Webhook::verify($payload, $signature, $secret, Webhook::DEFAULT_TOLERANCE_SECONDS, ($this->clock)());
        } catch (SignatureVerificationException $error) {
            $this->log(['refused' => $error->getErrorCode()], false);

            return self::answer(400, ['error' => $error->getErrorCode()]);
        }

        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $id = is_string($event['id'] ?? null) ? $event['id'] : '';
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;
        if ($type === 'webhook.test') {
            $this->log(['event' => $id, 'type' => $type, 'result' => 'Test event received'], true);

            return self::answer(200, ['received' => true, 'result' => 'test']);
        }
        if (!in_array($type, self::EVENT_TYPES, true) || $id === '' || $object === null) {
            return self::answer(200, ['received' => true, 'result' => 'ignored']);
        }

        $payInvoice = str_starts_with($type, 'refund.') ? ($object['invoice'] ?? null) : ($object['id'] ?? null);
        $row = is_string($payInvoice) ? $this->store->byPayInvoice($payInvoice) : null;
        if ($row === null || $row['company_id'] !== $this->settings->companyId()) {
            // Another Blesta, another company or a shop on the same Perly Pay account made it: theirs to handle.
            return self::answer(200, ['received' => true, 'result' => 'not_ours']);
        }
        // The webhook secret alone is not proof of payment: anyone who holds it (a developer who revealed it, a
        // leaked config) can sign an "invoice.fulfilled" for any invoice, or a "refund.address_received" naming their
        // own address. So the event only names the invoice or the refund; it is asked of Perly Pay with this
        // gateway's own key, and that answer decides everything below.
        $refund = str_starts_with($type, 'refund.');
        $retrieved = $refund ? $this->retrieveRefund($row['pay_invoice'], $object['id'] ?? null, $id, $type) : $this->retrieve($row['pay_invoice'], $id, $type);
        if (!isset($retrieved['id'])) {
            return $retrieved;
        }
        $object = $retrieved;
        if (!($refund ? self::refundAgrees($type, $object) : self::agrees($type, $object))) {
            // Signed, but Perly Pay says otherwise (forged, or overtaken by a later event): nothing to do or retry.
            return self::answer(200, ['received' => true, 'result' => 'not_what_perly_pay_says']);
        }
        $mode = ($object['livemode'] ?? false) === true ? 'live' : 'test';
        if ($mode !== $row['mode']) {
            return self::answer(200, ['received' => true, 'result' => 'other_mode']);
        }

        $fulfilled = !str_starts_with($type, 'refund.') && !empty($object['fulfilled_at'])
            && in_array($type, ['invoice.fulfilled', 'invoice.paid', 'invoice.overpaid'], true);
        if ($fulfilled) {
            return $this->handOver($row, $event, $object);
        }
        if ($this->store->hasEvent($row['pay_invoice'], $id)) {
            return self::answer(200, ['received' => true, 'result' => 'duplicate']);
        }
        $outcome = str_starts_with($type, 'refund.') ? $this->noteRefund($row, $event, $object) : $this->noteInvoice($row, $event, $object);
        $this->store->rememberEvent($row['pay_invoice'], $id);

        return $outcome;
    }

    /**
     * The payment, for Blesta to record — unless Blesta has it already.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @param array<string, mixed> $invoice
     * @return array{status: int, body: array<string, mixed>, record: array<string, mixed>|null}
     */
    private function handOver(array $row, array $event, array $invoice): array
    {
        if ($this->blesta->transaction($row['pay_invoice'], $this->gatewayId) !== null) {
            $this->store->setStatus($row['pay_invoice'], 'paid');

            return self::answer(200, ['received' => true, 'result' => 'already_recorded']);
        }
        if (!$this->store->claim($row['pay_invoice'], bin2hex(random_bytes(12)), ($this->clock)())) {
            // Another delivery is handing it over right now; if Blesta failed to record it, a retry
            // after the claim's ten minutes takes over.
            return self::answer(503, ['error' => 'in_progress']);
        }
        $payments = $this->payments($row['pay_invoice']);
        $first = self::firstPayment($payments);
        $lines = self::transfers($payments, $invoice);
        $this->store->setStatus($row['pay_invoice'], 'paid');

        $details = $this->base($row, $event) + [
            'result' => 'Paid: handed to Blesta to record',
            'transaction' => $row['pay_invoice'],
            'amount' => Money::fromMinor($row['amount']) . ' ' . $row['currency'],
            'invoices' => $row['invoices'] === [] ? 'none (account credit)' : implode(', ', array_map(static fn (array $l): string => '#' . $l['id'] . ' ' . $l['amount'], $row['invoices'])),
        ];
        foreach ($lines as $i => $line) {
            $details['transfer_' . ($i + 1)] = $line;
        }
        if (($invoice['status'] ?? '') === 'overpaid') {
            $details['overpaid'] = self::usd($invoice, 'received_usd') . ' received for ' . self::usd($invoice, 'total_usd') . '. Refund the difference in Perly Pay if you need to.';
        }
        $this->log($details, true);

        return [
            'status' => 200,
            'body' => ['received' => true, 'result' => 'paid'],
            'record' => [
                'client_id' => $row['client_id'],
                'amount' => Money::fromMinor($row['amount']),
                'currency' => $row['currency'],
                'invoices' => $row['invoices'],
                'status' => 'approved',
                'reference_id' => $first,
                'transaction_id' => $row['pay_invoice'],
                'parent_transaction_id' => null,
                'message' => Html::cut(implode('; ', array_map(static fn (string $l): string => preg_replace('/: https?:\/\/\S+$/', '', $l) ?? $l, $lines)), 255),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @param array<string, mixed> $invoice
     * @return array{status: int, body: array<string, mixed>, record: null}
     */
    private function noteInvoice(array $row, array $event, array $invoice): array
    {
        $type = (string) $event['type'];
        $details = $this->base($row, $event);
        $chain = is_array($invoice['payment'] ?? null) && is_string($invoice['payment']['chain'] ?? null) ? $invoice['payment']['chain'] : '';
        if ($chain !== '') {
            $details['network'] = Chains::name($chain);
        }
        switch ($type) {
            case 'invoice.paid':
            case 'invoice.overpaid':
                $result = 'Screening';
                $details['note'] = 'Payment received. Perly Pay is checking the sender; the payment is recorded when that is done.';
                break;
            case 'invoice.seen':
                $result = 'Seen';
                $details['note'] = 'Payment seen on the network. Waiting for confirmations.';
                break;
            case 'invoice.underpaid':
                $result = 'Underpaid';
                $details['received'] = self::usd($invoice, 'received_usd') . ' of ' . self::usd($invoice, 'total_usd');
                $details['note'] = 'Part paid; no payment recorded. The client can still send the rest.';
                break;
            case 'invoice.review':
                $result = 'Held for review';
                $details['reason'] = is_string($invoice['review_reason'] ?? null) ? $invoice['review_reason'] : 'review';
                $details['note'] = 'Decide in Perly Pay; no payment recorded until then.';
                break;
            case 'invoice.expired':
            case 'invoice.canceled':
                $result = $type === 'invoice.expired' ? 'Expired' : 'Canceled';
                if ($row['status'] === 'open') {
                    $this->store->setStatus($row['pay_invoice'], 'closed');
                }
                if (is_int($invoice['received_usd'] ?? null) && $invoice['received_usd'] > 0) {
                    $details['received'] = self::usd($invoice, 'received_usd') . ' of ' . self::usd($invoice, 'total_usd');
                    $details['note'] = 'Closed with part paid; no payment recorded. Refund it or accept it in Perly Pay.';
                } else {
                    $details['note'] = 'Closed unpaid; no payment recorded.';
                }
                break;
            case 'invoice.refunded':
                $result = ($invoice['status'] ?? '') === 'refunded' ? 'Refunded' : 'Partly refunded';
                break;
            default:
                $result = 'Info';
        }
        $this->log(['result' => $result] + $details, true);

        return self::answer(200, ['received' => true, 'result' => strtolower(str_replace(' ', '_', $result))]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @param array<string, mixed> $refund
     * @return array{status: int, body: array<string, mixed>, record: null}
     */
    private function noteRefund(array $row, array $event, array $refund): array
    {
        $chain = is_string($refund['chain'] ?? null) ? $refund['chain'] : '';
        $decimals = is_int($refund['decimals'] ?? null) ? $refund['decimals'] : 0;
        $asset = Chains::asset(is_string($refund['asset'] ?? null) ? $refund['asset'] : '', $chain);
        $txid = is_string($refund['txid'] ?? null) ? $refund['txid'] : '';
        $result = match ((string) $event['type']) {
            'refund.requested' => 'Refund opened',
            'refund.address_received' => 'Refund ready to send',
            'refund.sent' => 'Refund sent',
            'refund.confirmed' => 'Refund confirmed',
            default => 'Refund canceled',
        };
        $details = ['result' => $result] + $this->base($row, $event) + ['refund' => is_string($refund['id'] ?? null) ? $refund['id'] : ''];
        if ($chain !== '') {
            $details['network'] = Chains::name($chain);
        }
        $send = is_string($refund['amount_to_send'] ?? null) ? $refund['amount_to_send'] : (is_string($refund['amount'] ?? null) ? $refund['amount'] : '');
        if ($send !== '') {
            $details['amount'] = Money::units($send, $decimals) . ' ' . $asset;
        }
        if ($event['type'] === 'refund.address_received' && is_string($refund['refund_address'] ?? null)) {
            $details['send_to'] = $refund['refund_address'];
            $details['note'] = 'Send the amount from your wallet, then paste the transaction id in Perly Pay.';
        }
        if ($txid !== '') {
            $details['transaction'] = Chains::txUrl($chain, $txid) ?? $txid;
        }
        $this->log($details, true);

        return self::answer(200, ['received' => true, 'result' => 'refund_noted']);
    }

    /**
     * The invoice as Perly Pay's API has it, or the answer to give when it cannot be had: 503 (Perly Pay retries) when
     * there is no key or Perly Pay could not be asked, 200 "not_ours" when this key has no such invoice.
     *
     * @return array<string, mixed>
     */
    private function retrieve(string $payInvoice, string $eventId, string $type): array
    {
        if ($this->client === null) {
            return self::answer(503, ['error' => 'no_api_key']);
        }
        try {
            $invoice = $this->client->invoices->retrieve($payInvoice, ['max_retries' => 1]);
        } catch (PerlyPayException $error) {
            if ($error->getHttpStatus() === 404) {
                return self::answer(200, ['received' => true, 'result' => 'not_ours']);
            }
            $this->log(['result' => 'Not applied yet: the invoice could not be retrieved from Perly Pay, which sends the event again', 'event' => $eventId, 'type' => $type, 'invoice' => $payInvoice, 'error' => $error->getErrorCode()], false);

            return self::answer(503, ['error' => 'invoice_unavailable']);
        }

        return ($invoice['id'] ?? null) === $payInvoice ? $invoice : self::answer(200, ['received' => true, 'result' => 'not_ours']);
    }

    /**
     * The refund as Perly Pay's API has it, which must be the refund the event named and of the row's invoice, or the
     * answer to give when it cannot be had: 503 (Perly Pay retries) when there is no key or Perly Pay could not be
     * asked, 200 "not_ours" when this key has no such refund.
     *
     * @return array<string, mixed>
     */
    private function retrieveRefund(string $payInvoice, mixed $refundId, string $eventId, string $type): array
    {
        if (!is_string($refundId) || $refundId === '') {
            return self::answer(200, ['received' => true, 'result' => 'ignored']);
        }
        if ($this->client === null) {
            return self::answer(503, ['error' => 'no_api_key']);
        }
        try {
            $refund = $this->client->refunds->retrieve($refundId, ['max_retries' => 1]);
        } catch (PerlyPayException $error) {
            if ($error->getHttpStatus() === 404) {
                return self::answer(200, ['received' => true, 'result' => 'not_ours']);
            }
            $this->log(['result' => 'Not applied yet: the refund could not be retrieved from Perly Pay, which sends the event again', 'event' => $eventId, 'type' => $type, 'invoice' => $payInvoice, 'refund' => Html::cut($refundId, 64), 'error' => $error->getErrorCode()], false);

            return self::answer(503, ['error' => 'refund_unavailable']);
        }

        return ($refund['id'] ?? null) === $refundId && ($refund['invoice'] ?? null) === $payInvoice ? $refund : self::answer(200, ['received' => true, 'result' => 'not_ours']);
    }

    /**
     * Whether the refund Perly Pay returned is in a state the event can mean, now or after it moved on: one that was
     * opened is any refund; "address_received" needs one ready to send to an address (only then is there something
     * to send); "sent" one sent or confirmed; "confirmed" and "canceled" their own status.
     *
     * @param array<string, mixed> $refund
     */
    private static function refundAgrees(string $type, array $refund): bool
    {
        $status = is_string($refund['status'] ?? null) ? $refund['status'] : '';

        return match ($type) {
            'refund.requested' => in_array($status, ['requested', 'awaiting_address', 'ready_to_send', 'sent', 'confirmed', 'canceled'], true),
            'refund.address_received' => $status === 'ready_to_send' && is_string($refund['refund_address'] ?? null) && $refund['refund_address'] !== '',
            'refund.sent' => in_array($status, ['sent', 'confirmed'], true),
            'refund.confirmed' => $status === 'confirmed',
            'refund.canceled' => $status === 'canceled',
            default => false,
        };
    }

    /**
     * Whether the invoice Perly Pay returned is in a state the event can mean. An "invoice.fulfilled" needs a paid
     * invoice that is fulfilled; the rest need the status they are named for (a refund event, either refund status).
     *
     * @param array<string, mixed> $invoice
     */
    private static function agrees(string $type, array $invoice): bool
    {
        $status = is_string($invoice['status'] ?? null) ? $invoice['status'] : '';

        return match ($type) {
            'invoice.fulfilled' => in_array($status, ['paid', 'overpaid'], true) && !empty($invoice['fulfilled_at']),
            'invoice.paid', 'invoice.overpaid' => in_array($status, ['paid', 'overpaid'], true),
            'invoice.refunded' => in_array($status, ['refunded', 'partially_refunded'], true),
            default => 'invoice.' . $status === $type,
        };
    }

    /**
     * The invoice's transfers from the API, newest first; null when it could not be asked.
     *
     * @return list<array<string, mixed>>|null
     */
    private function payments(string $payInvoice): ?array
    {
        try {
            $page = $this->client?->payments->list(['invoice' => $payInvoice, 'limit' => 100], ['max_retries' => 1]);
        } catch (\Throwable) {
            return null;
        }
        $data = is_array($page['data'] ?? null) ? $page['data'] : null;

        return $data === null ? null : array_values(array_filter($data, static fn ($p): bool => is_array($p) && empty($p['dropped_at'])));
    }

    /**
     * The first transfer that arrived for the invoice (pay_…): the list is newest first, so the last one.
     *
     * @param list<array<string, mixed>>|null $payments
     */
    private static function firstPayment(?array $payments): ?string
    {
        if ($payments === null || $payments === []) {
            return null;
        }
        $first = $payments[count($payments) - 1];

        return is_string($first['id'] ?? null) && preg_match('/^pay_[A-Za-z0-9]{1,60}$/', $first['id']) === 1 ? $first['id'] : null;
    }

    /**
     * "0.0003125 BTC on Bitcoin testnet4: https://…" for each transfer, oldest first.
     *
     * @param list<array<string, mixed>>|null $payments
     * @param array<string, mixed> $invoice
     * @return list<string>
     */
    private static function transfers(?array $payments, array $invoice): array
    {
        $lines = [];
        foreach (array_reverse($payments ?? []) as $payment) {
            $chain = is_string($payment['chain'] ?? null) ? $payment['chain'] : '';
            $txid = is_string($payment['txid'] ?? null) ? $payment['txid'] : '';
            $amount = Money::units(is_string($payment['amount'] ?? null) ? $payment['amount'] : '0', is_int($payment['decimals'] ?? null) ? $payment['decimals'] : 0);
            $asset = Chains::asset(is_string($payment['asset'] ?? null) ? $payment['asset'] : '', $chain);
            $lines[] = $amount . ' ' . $asset . ' on ' . Chains::name($chain) . ': ' . (Chains::txUrl($chain, $txid) ?? $txid);
        }
        if ($lines === [] && is_array($invoice['payment'] ?? null)) {
            $rail = $invoice['payment'];
            $chain = is_string($rail['chain'] ?? null) ? $rail['chain'] : '';
            $decimals = is_int($rail['decimals'] ?? null) ? $rail['decimals'] : 0;
            $lines[] = Money::units(is_string($invoice['amount_received'] ?? null) ? $invoice['amount_received'] : '0', $decimals) . ' '
                . Chains::asset(is_string($rail['asset'] ?? null) ? $rail['asset'] : '', $chain) . ' on ' . Chains::name($chain);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function base(array $row, array $event): array
    {
        return [
            'event' => (string) $event['id'],
            'type' => (string) $event['type'],
            'invoice' => $row['pay_invoice'],
            'client' => $row['client_id'],
            'mode' => $row['mode'] === 'test' ? 'test (no real money)' : 'live',
        ];
    }

    /** @param array<string, mixed> $invoice */
    private static function usd(array $invoice, string $field): string
    {
        return '$' . Money::fromMinor(is_int($invoice[$field] ?? null) ? $invoice[$field] : 0);
    }

    /** @param array<string, mixed> $data */
    private function log(array $data, bool $success): void
    {
        $this->blesta->log($this->settings->callbackUrl(), $data, 'output', $success);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>, record: null}
     */
    private static function answer(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body, 'record' => null];
    }
}
