<?php

declare(strict_types=1);

namespace PerlyPay\Blesta;

use PerlyPay\Blesta\Vendor\Perly\Pay\Exception\PerlyPayException;
use PerlyPay\Blesta\Vendor\Perly\Pay\PerlyPay;

if (!defined('DS')) {
    exit;
}

/**
 * The pay button (buildProcess): the Perly Pay invoice for this payment —
 * the open one again while it can still be paid for the same amount, else
 * a new one — and the button that opens it.
 *
 * Blesta calls buildProcess on the client's "Review and confirm" step of
 * Pay (and the order form's), after the client chose Perly Pay, with the
 * amount and the invoices it pays worked out on the server. So the Perly Pay
 * invoice is made here, from Blesta's own figures, and nothing the browser
 * sends can change what is billed. The page is drawn again on every visit,
 * so the same client, invoices, amount and attempt always make the same
 * Perly Pay invoice (an idempotency key) and an open one is reused.
 *
 *   https://docs.blesta.com/developers/gateways/non-merchant-gateways
 */
final class Process
{
    /** Perly Pay invoices the client can still pay. */
    private const PAYABLE = ['open', 'seen', 'underpaid'];

    /** New invoices tried in one visit before giving up. */
    private const ATTEMPTS = 5;

    public function __construct(
        private readonly Settings $settings,
        private readonly Blesta $blesta,
        private readonly Store $store,
        private readonly ?PerlyPay $client,
    ) {
    }

    /**
     * @param array<string, mixed> $contact the client's contact, as buildProcess gets it
     * @param mixed $amount the total to pay, a decimal
     * @param array<int, mixed>|null $invoiceAmounts [['id' => 1001, 'amount' => '24.50'], …]; none for a credit
     * @param array<string, mixed>|null $options description, return_url (and recur, which a crypto payment does not do)
     */
    public function handle(array $contact, mixed $amount, ?array $invoiceAmounts, ?array $options, ?string $currency): string
    {
        if ($this->client === null || !$this->settings->ready()) {
            return self::note(Lang::t('PerlyPay.pay.not_set_up'));
        }
        $currency = strtoupper((string) $currency);
        if (!in_array($currency, Money::CURRENCIES, true)) {
            return self::note(Lang::t('PerlyPay.pay.currency', implode(Lang::t('PerlyPay.pay.or'), Money::CURRENCIES)));
        }
        $total = Money::normalise($amount);
        $minor = $total === null ? null : Money::toMinor($total);
        $clientId = (int) ($contact['client_id'] ?? 0);
        if ($minor === null || $minor <= 0 || $clientId <= 0) {
            return '';
        }
        $invoices = self::invoices($invoiceAmounts ?? []);
        $key = self::invoicesKey($invoices, $minor, $currency);
        $returnUrl = is_string($options['return_url'] ?? null) ? Html::url($options['return_url']) : null;

        try {
            $made = $this->reuse($clientId, $key, $minor, $currency)
                ?? $this->create($clientId, $invoices, $key, $minor, $currency, $returnUrl, is_string($options['description'] ?? null) ? $options['description'] : '');
        } catch (PerlyPayException $error) {
            $this->blesta->log(Settings::apiBase() . '/api/v1/invoices', ['action' => 'create invoice', 'client' => $clientId, 'invoices' => array_column($invoices, 'id'), 'code' => $error->getErrorCode(), 'request' => (string) $error->getRequestId()], 'output', false);

            return self::note(Lang::t('PerlyPay.pay.unavailable'));
        }
        $url = Html::url($made['url']);
        if ($url === null) {
            return self::note(Lang::t('PerlyPay.pay.unavailable'));
        }

        return $this->button($made['id'], $url, $returnUrl);
    }

    /**
     * The invoices a payment covers, as Blesta lists them, in a fixed order.
     *
     * @param array<int, mixed> $amounts
     * @return list<array{id: int, amount: string}>
     */
    public static function invoices(array $amounts): array
    {
        $lines = [];
        foreach ($amounts as $line) {
            $id = is_array($line) ? (int) ($line['id'] ?? $line['invoice_id'] ?? 0) : 0;
            $amount = is_array($line) ? Money::normalise($line['amount'] ?? null) : null;
            if ($id > 0 && $amount !== null && Money::toMinor($amount) > 0) {
                $lines[] = ['id' => $id, 'amount' => $amount];
            }
        }
        usort($lines, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $lines;
    }

    /**
     * What makes two payments the same: the invoices, their amounts and the total.
     *
     * @param list<array{id: int, amount: string}> $invoices
     */
    public static function invoicesKey(array $invoices, int $minor, string $currency): string
    {
        $parts = array_map(static fn (array $l): string => $l['id'] . '=' . $l['amount'], $invoices);

        return hash('sha256', implode('|', $parts) . '#' . $minor . strtoupper($currency));
    }

    /**
     * The last Perly Pay invoice for this payment, if it still bills the same and can be paid.
     *
     * @return array{id: string, url: string}|null
     */
    private function reuse(int $clientId, string $key, int $minor, string $currency): ?array
    {
        $row = $this->store->latestFor($this->settings->companyId(), $clientId, $key, (string) $this->settings->mode());
        if ($row === null || $row['status'] !== 'open') {
            return null;
        }
        try {
            $last = $this->client?->invoices->retrieve($row['pay_invoice'], ['max_retries' => 1]) ?? [];
        } catch (PerlyPayException) {
            return null;
        }
        if (in_array($last['status'] ?? '', self::PAYABLE, true) && ($last['total'] ?? null) === $minor && ($last['currency'] ?? '') === $currency) {
            return ['id' => $row['pay_invoice'], 'url' => is_string($last['url'] ?? null) ? $last['url'] : $row['url']];
        }
        if (!in_array($last['status'] ?? '', self::PAYABLE, true)) {
            $this->store->setStatus($row['pay_invoice'], 'closed');
        }

        return null;
    }

    /**
     * A new Perly Pay invoice.
     *
     * @param list<array{id: int, amount: string}> $invoices
     * @return array{id: string, url: string}
     */
    private function create(int $clientId, array $invoices, string $key, int $minor, string $currency, ?string $returnUrl, string $description): array
    {
        $company = $this->settings->companyId();
        $host = $this->settings->host();
        $for = trim($description) !== '' ? trim($description) : Lang::t('PerlyPay.pay.credit');
        $ids = implode(',', array_column($invoices, 'id'));
        $params = [
            'amount' => $minor,
            'currency' => $currency,
            'description' => Html::cut($host !== '' ? Lang::t('PerlyPay.pay.description', $for, $host) : $for, 300),
            'locale' => $this->settings->locale(),
            'metadata' => [
                'blesta_invoices' => Html::cut($ids, 500),
                'blesta_client' => (string) $clientId,
                'blesta_company' => (string) $company,
                'blesta' => Html::cut($host, 200),
                'source' => 'blesta',
                'module' => PERLYPAY_BLESTA_VERSION,
            ],
        ];
        if ($ids === '') {
            unset($params['metadata']['blesta_invoices']);
        }
        if ($returnUrl !== null && strlen($returnUrl) <= 2000) {
            $params['return_url'] = $returnUrl;
        }
        $email = $this->blesta->clientEmail($clientId);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254) {
            $params['customer_email'] = $email;
        }

        // The same Blesta, company, client, payment and attempt make the same Perly Pay invoice, however
        // often the page is drawn. A key Perly Pay already answered for an invoice that can no longer be
        // paid (this Blesta's table was emptied or restored) moves on to the next attempt.
        $attempt = $this->store->count($company, $clientId, $key) + 1;
        for ($tries = 0; $tries < self::ATTEMPTS; $tries++, $attempt++) {
            $idempotency = 'blesta_' . substr(hash('sha256', $this->settings->callbackUrl() . '|' . $clientId . '|' . $key . '|' . $attempt), 0, 40);
            $made = $this->client?->invoices->create($params, ['idempotency_key' => $idempotency]) ?? [];
            if (!is_string($made['id'] ?? null) || !is_string($made['url'] ?? null)) {
                throw new PerlyPayException('Perly Pay answered without an invoice.');
            }
            // A replay is the stored first answer, with the status of that moment: ask again when it is not new.
            $created = is_string($made['created'] ?? null) ? strtotime($made['created']) : false;
            if ($created !== false && $created < time() - 60) {
                $made = $this->client?->invoices->retrieve($made['id'], ['max_retries' => 1]) ?? $made;
            }
            $payable = in_array($made['status'] ?? 'open', self::PAYABLE, true) && ($made['total'] ?? $minor) === $minor && ($made['currency'] ?? $currency) === $currency;
            if ($this->store->byPayInvoice($made['id']) === null) {
                $this->store->add([
                    'pay_invoice' => $made['id'],
                    'company_id' => $company,
                    'client_id' => $clientId,
                    'invoices_key' => $key,
                    'invoices' => $invoices,
                    'mode' => (string) $this->settings->mode(),
                    'amount' => $minor,
                    'currency' => $currency,
                    'url' => $made['url'],
                ]);
            }
            if ($payable) {
                return ['id' => $made['id'], 'url' => $made['url']];
            }
            $this->store->setStatus($made['id'], 'closed');
        }
        throw new PerlyPayException('Perly Pay kept answering with invoices that cannot be paid.');
    }

    /** The button: a link to the payment page, which pay.js opens over Blesta's page in overlay mode. */
    private function button(string $invoice, string $url, ?string $returnUrl): string
    {
        $html = '<div class="perlypay">';
        $html .= '<a href="' . Html::e($url) . '" class="btn btn-success perlypay-button" rel="noopener"';
        if ($this->settings->overlay() && ($pin = self::payScript()) !== null) {
            $html .= ' data-perlypay="overlay" data-invoice="' . Html::e($invoice) . '"'
                . ' data-script="' . Html::e($pin['path']) . '" data-integrity="' . Html::e($pin['integrity']) . '"'
                . ' data-paid-url="' . Html::e($returnUrl ?? $url) . '"';
        }
        $html .= '>' . Html::e(Lang::t('PerlyPay.pay.button')) . '</a>';
        $html .= '<p class="perlypay-note small text-muted">' . Html::e(Lang::t('PerlyPay.pay.note')) . '</p>';
        if ($this->settings->mode() === 'test') {
            $html .= '<p class="perlypay-test small text-warning">' . Html::e(Lang::t('PerlyPay.pay.test_mode')) . '</p>';
        }
        $html .= '</div>';
        if ($this->settings->overlay() && ($script = self::overlayScript()) !== null) {
            $html .= '<script>' . $script . '</script>';
        }

        return $html;
    }

    /** @return array{path: string, integrity: string}|null pay.js as published, pinned at build time */
    public static function payScript(): ?array
    {
        $file = dirname(__DIR__) . '/pay-script.php';
        $pin = is_readable($file) ? require $file : null;

        return is_array($pin) && is_string($pin['path'] ?? null) && is_string($pin['integrity'] ?? null) ? $pin : null;
    }

    /** views/default/perlypay.js, printed inline: a gateway has no web path of its own that every Blesta serves. */
    private static function overlayScript(): ?string
    {
        $file = dirname(__DIR__) . '/views/default/perlypay.js';
        $code = is_readable($file) ? file_get_contents($file) : false;

        return is_string($code) && !str_contains(strtolower($code), '</script') ? $code : null;
    }

    private static function note(string $text): string
    {
        return '<p class="perlypay-note">' . Html::e($text) . '</p>';
    }
}
