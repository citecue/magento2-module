# Citecue AI Delivery for Magento 2 (`Citecue_Delivery`)

Magento 2 middleware that serves **Citecue AI-optimized (auto-fixed) versions of your pages to AI bots and crawlers** — GPTBot, ChatGPT-User, ClaudeBot, PerplexityBot, and the rest — while every human visitor keeps getting your normal storefront, byte for byte.

It is the Magento counterpart of Citecue's Cloudflare Worker snippet and WordPress plugin, built on the **authenticated delivery API v2** (`/api/delivery/v2/*`, `X-Citecue-Channel: magento`).

```text
                                ┌────────────────────────────┐
 AI crawler (GPTBot, …)  ──────►│  Citecue_Delivery           │──── optimized? ────► serve Citecue version
                                │  (front-controller          │        │ (200/304, ETag-cached)
                                │   middleware, fail-open)    │        └─ miss/404 ──► normal Magento page
 Human visitor ────────────────►│  passthrough, untouched     │──────────────────────► normal Magento page
                                └────────────────────────────┘
```

## How it works

1. **Detection** — every frontend `GET` is matched against Citecue's AI-crawler registry (case-insensitive UA token match, longest token wins). The module ships a bundled snapshot of the registry and refreshes it daily from the keyless `GET /api/delivery/v1/crawlers` feed via cron, so new crawlers are picked up without a module update. Matching never makes a network call on the request path.
2. **Delivery** — for a detected crawler, the module calls `GET /api/delivery/v2/page?k=<projectPublicKey>&u=<requestedUrl>&b=<crawlerId>` with your `ck_live_…` org API key (`Authorization: Bearer`) and `X-Citecue-Channel: magento`:
   * **200** → the optimized HTML is served to the crawler (with `X-Citecue-Mode` passed through) and cached locally with its ETag;
   * **304** (revalidation via `If-None-Match`) → the locally cached body is served;
   * **404** (`not_optimized` miss sentinel) → the request **passes through** to normal Magento rendering. The API records the passthrough hit, which powers the Agent Traffic dashboard.
3. **Analytics** — hit recording (served/passthrough, per crawler, channel `magento`) happens server-side inside the same API request. No extra beacon.
4. **llms.txt** — `https://yourstore.com/llms.txt` serves the project's llms.txt (llmstxt.org convention) fetched from `GET /api/delivery/v2/llms.txt`, gated on both the module toggle and the project's "Serve llms.txt" setting in Citecue.

### Fail-open, always

The middleware can never take the storefront down. Any failure — timeout, DNS, TLS, 5xx, invalid key, malformed response, even an exception inside the module itself — results in the normal Magento page. Additionally:

* **Timeouts** are short and configurable (default 3s total / 2s connect).
* A transport error trips a **60s circuit breaker**: while open, no API calls are made at all; crawlers get locally cached copies (up to 24h stale) or the normal page.
* Misses are **negative-cached for 60s** (mirroring the API's miss `Cache-Control`), so unoptimized pages don't trigger an API call per crawler hit.

### Full-page cache interplay

* The middleware runs as the **outermost** front-controller plugin (`sortOrder="-100"`, before `Magento_PageCache`), so crawlers are answered before the builtin FPC.
* Belt-and-braces: a plugin on `PageCache\Kernel::load` forces an FPC **miss** for detected AI crawlers, so a cached page can never answer a crawler behind the middleware's back — regardless of plugin ordering.
* Served crawler responses are `Cache-Control: private, no-store`, so neither the builtin FPC nor any shared cache ever stores the crawler-only variant for regular visitors. Passthrough renders are identical to any anonymous render (Magento does not vary on User-Agent), so FPC behavior for humans is completely unchanged.

**Using Varnish?** Varnish answers cache hits before PHP runs, so add a pass for AI crawlers in `vcl_recv` (otherwise crawlers only reach the middleware on cache misses):

```vcl
if (req.http.User-Agent ~ "(?i)(GPTBot|OAI-SearchBot|ChatGPT-User|ClaudeBot|Claude-User|Claude-SearchBot|anthropic-ai|PerplexityBot|Perplexity-User|GoogleOther|Bytespider|CCBot|cohere-ai|meta-externalagent|meta-externalfetcher|Amazonbot|DuckAssistBot|MistralAI-User)") {
    return (pass);
}
```

The same applies to a CDN in front of the store (Fastly, Cloudflare cache rules): bypass cache for these user agents. (If the site is on Cloudflare, consider Citecue's Worker snippet instead — it serves at the edge without touching Magento.)

## Installation

### Composer (Adobe Commerce Marketplace)

Once the extension is purchased/added on the [Commerce Marketplace](https://commercemarketplace.adobe.com/),
your `repo.magento.com` keys give you the package directly:

```bash
composer require citecue/module-delivery
bin/magento module:enable Citecue_Delivery
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
bin/magento cache:flush
```

### Composer (VCS repository)

```bash
composer config repositories.citecue-delivery vcs https://github.com/henry-mosh/citecue-magento
composer require citecue/module-delivery
bin/magento module:enable Citecue_Delivery
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
bin/magento cache:flush
```

### app/code

```bash
mkdir -p app/code/Citecue/Delivery
cp -R <this repo>/* app/code/Citecue/Delivery/
bin/magento module:enable Citecue_Delivery
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Requires Magento 2.4.4+ (PHP 8.1–8.4).

## Configuration

**Stores → Configuration → Citecue → AI Delivery**

| Field | What it does |
|---|---|
| **Enabled** | Master switch. Off = the module does nothing at all. |
| **API Key** | Your organization API key (`ck_live_…`) from the Citecue dashboard → Organization → API keys. Stored encrypted (`Magento\Config\Model\Config\Backend\Encrypted`). |
| **Project Public Key** | The public key of the Citecue project matching this store's domain. Click **Test Connection** to list your projects and pick the key. |
| **Test Connection** | Calls `GET /api/delivery/v2/config` with the entered key and lists your projects (domain, public key, delivery enabled, llms.txt) with a one-click "Use this key". |
| **Serve llms.txt** | Serve `/llms.txt` at the store's domain root. Also requires the project's "Serve llms.txt" toggle in Citecue. |
| **Allowed API Hosts** | One host per line. The base URL and every credentialed request are restricted to these hosts (`app.citecue.com` is always allowed); private/loopback/link-local addresses are always rejected. Add a Citecue staging/self-hosted host here before pointing the base URL at it. |
| **Citecue API Base URL** | Default `https://app.citecue.com`. Only change on Citecue support's instruction; must be https and on the Allowed API Hosts list. |
| **Request / Connect Timeout** | Delivery API budget; on timeout the crawler gets the normal page. |
| **Local Cache TTL** | `0` (default) = revalidate with the API on every crawler request — exact Agent Traffic analytics, cheap 304s. Set to e.g. `60`–`300` to serve repeat crawler hits from the local cache without any API round trip. |
| **Excluded Path Prefixes** | One per line; matched on full path segments (`checkout` excludes `/checkout/cart` but not `/checkout-guide`). Defaults cover checkout, customer, cart, API and asset paths. |
| **Debug Logging** | Logs every detection and API call to `var/log/debug.log`. |

All fields are store-scoped: in multi-store setups, map each store view (domain) to its own Citecue project public key.

Everything the module stores locally (optimized bodies, ETags, llms.txt, the crawler registry) lives in the dedicated **Citecue Delivery** cache type — flushable from Cache Management.

### Getting the keys

1. In the Citecue dashboard, open **Organization → API keys** and mint a key (`ck_live_…`).
2. Enable **Auto-Fix delivery** for the project (the kill switch in Citecue — when off, the API answers every page request with a miss and the middleware passes everything through).
3. Paste the key in Magento, hit **Test Connection**, click **Use this key** next to the project matching the store's domain, and save.

## What crawlers are served?

The registry mirrors Citecue's serving list: OpenAI (GPTBot, OAI-SearchBot, ChatGPT-User), Anthropic (ClaudeBot, Claude-User, Claude-SearchBot, anthropic-ai), Perplexity (PerplexityBot, Perplexity-User), GoogleOther, Bytespider, CCBot, cohere-ai, Meta (meta-externalagent, meta-externalfetcher), Amazonbot, DuckAssistBot, MistralAI-User. Robots.txt-only tokens (Google-Extended, Applebot-Extended) never send requests and are never served. Ordinary search engines (Googlebot, Bingbot) and human visitors are **never** intercepted.

Detection is UA-based, matching the rest of the Citecue delivery layer. A UA spoofer could see the optimized version of your pages — content derived from your own public site, so this is a documented, low-severity non-issue.

## Security & trust model

- The module's settings (API key, base URL, **Allowed API Hosts**) live under the `Citecue_Delivery::config` admin ACL. An admin with that permission is already trusted with the org API key — the same boundary that lets them read or rotate it — so restricting *where* the key is sent is defense-in-depth, not a boundary against that admin.
- Credentialed delivery requests are constrained to the **Allowed API Hosts** list (default `app.citecue.com`), over **HTTPS only**, and IP-literal hosts in private/loopback/link-local/reserved ranges are always rejected — even if allowlisted. Pointing the base URL at a new host is therefore an explicit, allowlisted decision, enforced at save time, when resolving stored config, and once more in the API client before the Bearer key is sent. The Magento curl client does not follow redirects, so a trusted host can't 3xx the request to an internal address.
- **Accepted residuals** (a deliberate trade-off — the allowlist was chosen over wiping the stored key on every base-URL edit): an admin who *deliberately* allowlists a host they control can direct the key there, and DNS rebinding of an explicitly-allowlisted hostname to a private address is not blocked (hostnames aren't resolved on the request path). Both require a trusted `Citecue_Delivery::config` admin to first allowlist the host. If your threat model needs a harder boundary, gate the Citecue configuration section behind a dedicated, more restricted admin role.

## Repository layout

```text
Model/Config.php                 typed config reader (store-scoped)
Model/CrawlerMatcher.php         pure UA matcher (longest-token-wins, upstream parity)
Model/CrawlerRegistry.php        bundled registry + cached daily feed
Model/PathMatcher.php            pure excluded-path matcher (segment-boundary)
Model/Api/Client.php             delivery API v2/v1 HTTP client (never throws, https-only)
Model/DeliveryService.php        middleware brain: caching, ETag, breaker, fail-open
Model/Cache/Type.php             "citecue_delivery" cache type
Model/Config/Backend/BaseUrl.php https + allowed-host validation of the API base URL
Plugin/FrontControllerPlugin.php outermost dispatch interception (serve or proceed)
Plugin/PageCacheKernelPlugin.php FPC bypass for detected crawlers
Controller/Router.php            /llms.txt router (Magento_Robots pattern)
Controller/Llms/Index.php        llms.txt action (ETag/304 to clients)
Controller/Adminhtml/...         Test Connection AJAX endpoint
Block/ + view/adminhtml/...      Test Connection button
Cron/RefreshCrawlerRegistry.php  daily registry refresh
Test/Unit/...                    unit tests for the pure matchers
```

## Development

Unit tests target the framework-free classes and run inside a Magento installation:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Citecue/Delivery/Test/Unit
```

The codebase is clean against the [Magento Coding Standard](https://github.com/magento/magento-coding-standard)
(0 errors, 0 warnings) and PHPCompatibility for PHP 8.1–8.4:

```bash
vendor/bin/phpcs --standard=Magento2 app/code/Citecue/Delivery
vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.1-8.4 \
    --extensions=php,phtml app/code/Citecue/Delivery
```

## License

MIT — see [LICENSE](LICENSE).
