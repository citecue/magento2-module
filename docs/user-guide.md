# Citecue AI Delivery for Magento 2 — User Guide

Extension: `Citecue_Delivery` (Composer package `citecue/module-delivery`)

## What it does

Citecue AI Delivery serves **AI-optimized versions of your pages to AI bots
and crawlers** — GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot and the rest
of the Citecue registry — while every human visitor keeps getting your normal
storefront, byte for byte.

When a detected AI crawler requests a page:

1. The module asks the Citecue delivery API for the optimized version of that
   URL (authenticated with your organization API key).
2. If one exists, it is served to the crawler (and cached locally with its
   ETag). If not, the crawler gets your normal Magento page.
3. Every request is recorded in Citecue's **Agent Traffic** analytics
   (served or passthrough, per crawler), with no extra tracking beacon.

**Fail-open, always:** any failure — timeout, DNS, TLS, API outage, invalid
key, even an error inside the module itself — results in the normal Magento
page. The middleware cannot take your storefront down.

## Configuration

All settings live under **Stores → Configuration → Citecue → AI Delivery**
and are store-scoped: in multi-store setups, each store view (domain) can map
to its own Citecue project.

### General

| Field | Description |
|---|---|
| **Enabled** | Master switch. Off = the module does nothing at all. |
| **API Key** | Your organization API key (`ck_live_…`) from the Citecue dashboard → Organization → API keys. Stored encrypted. |
| **Project Public Key** | The public key of the Citecue project matching this store's domain. Use **Test Connection** to pick it. |
| **Test Connection** | Verifies the API key and lists your projects (domain, public key, delivery status, llms.txt) with a one-click **Use this key**. |
| **Serve llms.txt** | Serve `https://yourstore.com/llms.txt` (llmstxt.org convention). Also requires the project's "Serve llms.txt" toggle in Citecue. |

### Advanced

| Field | Description |
|---|---|
| **Allowed API Hosts** | One host per line. Credentialed API requests are restricted to these hosts (`app.citecue.com` is always allowed). Only add a host on Citecue support's instruction. |
| **Citecue API Base URL** | Default `https://app.citecue.com`. Must be HTTPS and on the Allowed API Hosts list. |
| **Request / Connect Timeout** | Delivery API time budget (defaults 3s / 2s). On timeout the crawler gets the normal page. |
| **Local Cache TTL** | `0` (default) = revalidate with the API on every crawler request — exact analytics, cheap 304 responses. Set to e.g. `60`–`300` to serve repeat crawler hits from the local cache without any API round trip. |
| **Excluded Path Prefixes** | One per line, matched on full path segments (`checkout` excludes `/checkout/cart` but not `/checkout-guide`). Defaults cover checkout, customer, cart, API and asset paths. |
| **Debug Logging** | Logs every detection and API call to `var/log/debug.log`. Use only while diagnosing. |

## Initial setup, step by step

1. In the Citecue dashboard, open **Organization → API keys** and mint a key
   (`ck_live_…`).
2. Enable **Auto-Fix delivery** for your project in Citecue (this is the kill
   switch — when off, the API answers every request with a miss and the
   module passes everything through).
3. In Magento, open **Stores → Configuration → Citecue → AI Delivery**, set
   **Enabled** = Yes and paste the API key.
4. Click **Test Connection**. Click **Use this key** next to the project
   whose domain matches this store.
5. **Save Config** and flush the configuration cache.

## Which crawlers are served?

The registry mirrors Citecue's serving list: OpenAI (GPTBot, OAI-SearchBot,
ChatGPT-User), Anthropic (ClaudeBot, Claude-User, Claude-SearchBot,
anthropic-ai), Perplexity (PerplexityBot, Perplexity-User), GoogleOther,
Bytespider, CCBot, cohere-ai, Meta (meta-externalagent,
meta-externalfetcher), Amazonbot, DuckAssistBot and MistralAI-User.

Ordinary search engines (Googlebot, Bingbot) and human visitors are **never**
intercepted. The registry refreshes itself daily from Citecue via cron, so
new AI crawlers are picked up without a module update.

## Caching

- Everything the module stores locally (optimized pages, ETags, llms.txt,
  the crawler registry) lives in the dedicated **Citecue Delivery** cache
  type — flushable from **System → Cache Management** at any time.
- Responses served to crawlers are `Cache-Control: private, no-store`, so
  the full-page cache and CDNs never store the crawler-only variant for
  regular visitors. Full-page cache behavior for humans is unchanged.
- Using Varnish or a CDN? See the Installation Guide for the required
  cache-bypass rule for AI crawler user agents.

## Troubleshooting

| Symptom | Check |
|---|---|
| Test Connection fails with "Invalid API key" | Re-copy the `ck_live_…` key from Citecue → Organization → API keys. |
| Crawlers get normal pages only | Is **Auto-Fix delivery** enabled for the project in Citecue? Is the page optimized there? Is the path excluded by a prefix? |
| No crawler hits appear in Agent Traffic | Behind Varnish/CDN, add the crawler cache-bypass rule (crawlers may be answered from the edge cache before Magento runs). |
| Verify what the module is doing | Enable **Debug Logging** and watch `var/log/debug.log`; test with `curl -A "GPTBot" https://yourstore.com/some-page`. |
| API outage | Nothing to do: a 60-second circuit breaker stops API calls and crawlers get locally cached copies or the normal page. |

## Security notes

- The API key is stored encrypted and marked as sensitive configuration
  (excluded from `app:config:dump`).
- Credentialed API requests only ever go to HTTPS hosts on the admin-managed
  **Allowed API Hosts** allowlist; private, loopback, link-local and reserved
  addresses are always rejected.
- All settings are protected by the `Citecue_Delivery::config` admin ACL
  resource, so access can be restricted per admin role.

## Support

- Issues: https://github.com/henry-mosh/citecue-magento/issues
- Citecue dashboard: https://app.citecue.com
