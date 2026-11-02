<?php

/**
 * Perly Pay for Blesta: bitcoin and USDC/USDT, paid straight to your own
 * wallet. Perly Pay never holds the money; it charges 2.5¢ a payment, never
 * more than 0.5% of it, billed after the month.
 *
 * A non-merchant gateway (the client pays on Perly Pay's checkout, over
 * Blesta's page or on its own). Its parts:
 *
 *   perly_pay.php        this file: the class Blesta calls (buildProcess, validate, success, refund, settings)
 *   config.json          name, version, currencies (USD and EUR: what Perly Pay bills in)
 *   language/            en_us and uk_ua
 *   views/default/       the logo and the overlay's script
 *   lib/                 the rules, testable without Blesta
 *
 * Perly Pay's webhooks land on Blesta's own callback for non-merchant gateways,
 * https://your-blesta/callback/gw/<company id>/perly_pay/, which calls validate().
 *
 *   https://docs.blesta.com/developers/gateways/non-merchant-gateways
 */

if (!defined('DS')) {
    exit;
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

use PerlyPay\Blesta\BlestaFunctions;
use PerlyPay\Blesta\Callback;
use PerlyPay\Blesta\Process;
use PerlyPay\Blesta\RecordStore;
use PerlyPay\Blesta\Refund;
use PerlyPay\Blesta\Returned;
use PerlyPay\Blesta\Settings;
use PerlyPay\Blesta\SettingsForm;

class PerlyPay extends NonmerchantGateway
{
    /** @var array<string, mixed> the saved settings, decrypted */
    private $meta = [];

    /** @var int|null this instance's id (Gateway keeps its own copy private) */
    private $perlyGatewayId = null;

    public function __construct()
    {
        Language::loadLang('perly_pay', null, __DIR__ . DS . 'language' . DS);
        $this->loadConfig(__DIR__ . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function setMeta(array $meta = null)
    {
        $this->meta = $meta ?? [];
    }

    public function setGatewayId($id)
    {
        parent::setGatewayId($id);
        $this->perlyGatewayId = $id === null ? null : (int) $id;
    }

    public function encryptableFields()
    {
        return ['api_key', 'webhook_secret'];
    }

    public function getSettings(array $meta = null)
    {
        $test = isset($_GET['perlypay_test']) && $_GET['perlypay_test'] === '1';

        return SettingsForm::html($this->settings($meta ?? []), isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null, $test);
    }

    public function editSettings(array $meta)
    {
        $checked = SettingsForm::validate($meta);
        if ($checked['errors'] !== []) {
            $this->Input->setErrors($checked['errors']);
        }

        return $checked['meta'];
    }

    public function install()
    {
        try {
            (new RecordStore())->install();
        } catch (Throwable $error) {
            $this->Input->setErrors(['perly_pay' => ['install' => 'Could not make the table perly_pay_invoices: ' . $error->getMessage()]]);
        }
    }

    public function upgrade($current_version)
    {
        // The table is the same in every version so far; this makes it if it is missing.
        $this->install();
    }

    public function uninstall($gateway_id, $last_instance)
    {
        if ($last_instance) {
            (new RecordStore())->uninstall();
        }
    }

    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        $settings = $this->settings($this->meta);

        return (new Process($settings, $this->blesta(), new RecordStore(), $settings->client(1)))
            ->handle($contact_info, $amount, $invoice_amounts, $options, $this->currency ?? null);
    }

    public function validate(array $get, array $post)
    {
        $settings = $this->settings($this->meta);
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_PERLY_SIGNATURE'] ?? null;
        $answer = (new Callback($settings, $this->blesta(), new RecordStore(), $settings->client(1), $this->perlyGatewayId))
            ->handle(is_string($payload) ? $payload : '', is_string($signature) ? $signature : null);

        // Blesta's callback answers 200 on its own; Perly Pay retries only what is not 2xx.
        if ($answer['status'] !== 200 && !headers_sent()) {
            http_response_code($answer['status']);
        }
        if ($answer['status'] === 400) {
            $this->Input->setErrors($this->getCommonError('invalid'));
        } elseif ($answer['status'] >= 500) {
            $this->Input->setErrors($this->getCommonError('general'));
        }

        return $answer['record'];
    }

    public function success(array $get, array $post)
    {
        $settings = $this->settings($this->meta);

        return (new Returned($settings, new RecordStore(), $settings->client(0)))->handle($get);
    }

    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $settings = $this->settings($this->meta);
        $result = (new Refund($settings, new RecordStore(), $settings->client(), $this->blesta()))
            ->handle(is_scalar($reference_id) ? (string) $reference_id : null, is_scalar($transaction_id) ? (string) $transaction_id : null, $amount, $this->currency ?? null);
        if (!$result['ok']) {
            $this->Input->setErrors(['refund' => ['failed' => $result['error']]]);

            return null;
        }

        return $result['record'];
    }

    /** @param array<string, mixed> $meta */
    private function settings(array $meta): Settings
    {
        return new Settings($meta, [
            'company_id' => (int) Configure::get('Blesta.company_id'),
            'callback_base' => (string) Configure::get('Blesta.gw_callback_url'),
            'language' => (string) Configure::get('Blesta.language'),
        ]);
    }

    private function blesta(): BlestaFunctions
    {
        return new BlestaFunctions(function (string $url, string $data, string $direction, bool $success): void {
            $this->log($url, $data, $direction, $success);
        });
    }
}
