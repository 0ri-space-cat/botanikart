# BotanikArt payment worker

This Cloudflare Worker creates a T-Bank hosted payment session. It never exposes the terminal password to the GitHub Pages frontend. It reads the current public catalogue before creating a payment and refuses products that are missing or no longer available.

## Configuration

Deploy from this directory with Wrangler. Set the terminal values only as Cloudflare secrets:

```sh
wrangler secret put TBANK_TERMINAL_KEY
wrangler secret put TBANK_PASSWORD
```

If T-Bank issued a separate API bearer token, add `TBANK_API_TOKEN` as another secret. If an online cash register is connected, set `TBANK_TAXATION` and `TBANK_TAX` to the values confirmed in T-Business so that the Worker sends a fiscal receipt with each payment.

`SITE_ORIGINS`, `CATALOG_URL`, success/failure URLs, and the T-Bank API URL are public configuration in `wrangler.toml`; they are not secrets.

## API

`POST /api/create-payment`

Request JSON:

```json
{
  "productId": "flowwow-12345678",
  "email": "buyer@example.com",
  "phone": "+79990000000",
  "consent": true
}
```

At least one of email or phone is required. The Worker responds with a short-lived T-Bank `PaymentURL` and an order ID. The amount covers the product only; delivery is arranged and paid for separately with the studio after payment. Card data stays on T-Bank's payment page.

`GET /health` returns whether terminal credentials are configured; it never returns credential values.

## Before enabling checkout

1. Configure the test terminal and test receipt settings.
2. Run T-Bank's test payments and verify the receipt.
3. Replace the test terminal secrets with the working terminal secrets and the actual tax settings.
4. Add the Worker URL to the public site's `data/payment-config.js`.

Do not commit terminal passwords or API tokens.
