# Perly Pay for Blesta

Take bitcoin, USDC and USDT on your Blesta invoices, paid straight to your
own wallet. Perly Pay never holds the money and takes nothing from a payment:
it costs 2.5¢ a payment, never more than 0.5% of it, billed after the month,
and a month of up to 21 payments costs $0.

A non-merchant gateway for Blesta 6 (and Blesta 5 on PHP 8.1 or later:
the gateway interface is the same), with PHP's `curl` and `json`. Nothing to install with Composer: Perly Pay's PHP SDK is inside,
under its own namespace.

## Install

1. Unzip `perly-pay-blesta.zip` over your Blesta folder. It adds
   `components/gateways/nonmerchant/perly_pay/`.
2. In Blesta: **Settings → Company → Payment Gateways → Available**, find **Perly Pay**
   and press **Install**. It makes one table, `perly_pay_invoices`.

## Configure

| Setting | What to put |
| --- | --- |
| Secret key | Perly Pay → Developers → API keys. `sk_test_…` for test mode, `sk_live_…` for real money. Save, then press **Test connection**. |
| Webhook signing secret | In Perly Pay → Developers → Webhooks, add `https://your-blesta/callback/gw/1/perly_pay/` (the settings show the exact address; `1` is the company) with the invoice and refund events, and paste its `whsec_…` secret. Until it is saved, clients see no button. |
| Payment page | **Overlay** opens the checkout over Blesta's page (add your Blesta address in Perly Pay → Settings → "Sites that may embed the checkout"); **Redirect** sends the client to it. |
| Currencies | Perly Pay bills in USD or EUR, and the gateway says so to Blesta (`config.json`), so Blesta offers it only on payments in those two currencies. |

Running several companies? Each has its own instance, its own callback
address (`/callback/gw/<company id>/perly_pay/`) and its own webhook in
Perly Pay.

## How it works

- The client chooses **Perly Pay** under Pay (or in the order form) and
  reaches "Review and confirm". Blesta asks the gateway for its button with
  the amount and the invoices worked out on the server; the gateway makes a
  Perly Pay invoice for exactly that (or reuses the open one for the same
  payment) and shows **Pay with crypto**, which opens it.
- Perly Pay's signed webhook `invoice.fulfilled` (paid in full and the
  sender screened) hands the payment to Blesta: the client, the amount
  billed, status "approved" and the invoices it pays. Blesta records the
  transaction, applies it and emails the client. The transaction id is the
  Perly Pay invoice (`inv_…`); its reference is the first transfer
  (`pay_…`); its note names the coin and network. The gateway log has the
  transaction links.
- A payment Blesta already has is never handed over again, so a replayed or
  doubled webhook records nothing twice and sends no second email. Two
  deliveries at the same moment cannot both hand it over: the first claims
  it.
- Part payments, expiry, review holds and refunds are written to the
  gateway log (Tools → Logs → Gateway), without recording a payment.
- When the client comes back, Blesta's "payment received" page shows the
  payment as approved or pending. That page records nothing; the webhook
  does.
- A refund from Blesta (the transaction → Refund) opens a refund in Perly
  Pay in the coin that was paid (the same share of it for a partial
  refund). Perly Pay emails the client for an address; you send from your
  wallet and paste the transaction id in Perly Pay. Blesta marks the
  transaction refunded once the refund is open; its note says what is left
  to do. Blesta refunds a transaction once: refund the rest of the same
  payment from Perly Pay's dashboard.
- Crypto cannot be pulled from a wallet, so there are no automatic
  recurring charges: each invoice is paid when the client pays it.

Uninstalling the gateway from the last company removes its table.

## Test

With a `sk_test_…` key, pay an invoice in the client area with
**Perly Pay**. Pay it with test coins, or from the API (the invoice id
`inv_…` is on the checkout page):

```bash
curl -X POST https://pay.perly.io/api/v1/invoices/inv_…/simulate \
  -H "Authorization: Bearer $PERLY_PAY_SECRET_KEY" -H "Content-Type: application/json" -d '{}'
```

Within seconds the Blesta invoice is **Paid**.

## Go live

Turn on live mode in Perly Pay and connect your wallet's public keys, paste
a `sk_live_…` key, add the callback address again as a **live** webhook and
paste its secret, then pay one small real invoice and refund it.

## Security

- The callback acts only on a valid `Perly-Signature` (HMAC-SHA256 of the
  timestamp and the raw body, at most five minutes old, compared in constant
  time), and only on Perly Pay invoices this company's gateway made, in the
  key's own mode. A bad signature gets 400 and changes nothing.
- What a payment records comes from Blesta's own table, written when the
  invoice was made from Blesta's figures, never from the webhook's body or
  the address the client comes back to.
- Both secrets are stored encrypted by Blesta. Keys, secrets, signatures and
  clients' emails never go to the gateway log.
- Everything the gateway prints is escaped; every PHP file refuses to run
  outside Blesta.

## Blesta documentation used

- Non-merchant gateways (`buildProcess`, `validate`, `success`, `refund`, settings): https://docs.blesta.com/developers/gateways/non-merchant-gateways
- Gateway configuration (`config.json`): https://docs.blesta.com/developers/gateways/gateway-configuration
- Payment gateways overview (install, upgrade, uninstall, errors): https://docs.blesta.com/developers/gateways/overview
- Translating Blesta (language files): https://docs.blesta.com/developers/translating-blesta
- Extension Development Kit, gateways (the transaction data Blesta records, the callback URL, WHMCS to Blesta): https://github.com/blesta/extension-dev-kit

## Licence

MIT. Perly Pay's PHP SDK inside (`vendor/perly-pay-sdk`) is MIT too.
