# Changelog

All notable changes to `citecue/module-delivery` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-10

### Fixed
- A 404 from the delivery API (fix undone, or delivery switched off in
  Citecue) now deletes the locally cached copy for that Accept variant instead
  of keeping it.
- A 401 (revoked API key) is treated as "delivery off", never as an outage: the
  module passes requests through, never serves stale content, and rotates a
  per-project cache epoch that instantly orphans every cached entry for that
  project. The API is re-checked at most once every 60 seconds.
- Saving the module's admin configuration section flushes the module's delivery
  state cache (entries, miss markers, circuit breaker and key flags) through a
  new observer, so a replaced API key takes effect immediately. The cached
  crawler registry survives the flush. Note that CLI changes
  (`bin/magento config:set`) do not fire this event; run
  `bin/magento cache:clean citecue_delivery` after them.
- Stale-if-error is capped at 300 seconds: during transport errors or 5xx
  responses, a cached page is served stale for at most 300 seconds after its
  last successful validation (matching the API's `Cache-Control: max-age=300`).
  The 24-hour local entry TTL now only enables `If-None-Match` / 304
  revalidation reuse and no longer allows day-long stale serving.

### Added
- HEAD requests from AI crawlers are answered like GET, with the same headers
  and an empty body.
- The crawler's `Accept` header is forwarded (normalized). Crawlers asking for
  `text/markdown` receive the markdown representation when the API has one.
  Responses carry the stored `Content-Type` and `Vary: Accept`, and HTML and
  markdown variants are cached separately.
- Test Connection warns when the configured Citecue API Base URL is not on the
  Allowed API Hosts list. Such a URL is ignored at runtime and requests go to
  the default `https://app.citecue.com`; previously this happened silently.

### Security
- The SSRF IP guard is now an explicit CIDR denylist covering loopback,
  RFC 1918, link-local, CGNAT (`100.64.0.0/10`), documentation ranges
  (including `203.0.113.0/24`), benchmarking, multicast and reserved space, and
  the IPv6 unspecified, loopback, IPv4-mapped, NAT64, Teredo, documentation,
  6to4, ULA, link-local, site-local and multicast ranges. It behaves identically
  on every supported PHP version. Previously it relied on PHP's filter flags,
  which stopped covering documentation ranges in PHP 8.5 and never covered CGNAT.
- Numeric-form hosts such as `127.1` or `2130706433` are rejected.

### Changed
- `/llms.txt` is served with `Cache-Control: private, max-age=300` (previously
  `public, max-age=300`), so turning llms.txt off propagates within about five
  minutes even behind Varnish or a CDN. The llms.txt 404 response remains
  public with `max-age=60`.
- Documentation: corrected repository and support URLs, the Citecue dashboard
  page name for API keys, and the README and guides for the new caching,
  breaker and delivery semantics.

## [1.0.0] - 2026-08-06

Initial release.

### Added
- AI-crawler delivery middleware: serves Citecue AI-optimized page versions to
  detected AI bots and crawlers (GPTBot, ClaudeBot, PerplexityBot, and the
  rest of the Citecue registry) via the authenticated delivery API v2, while
  every other visitor gets the normal storefront untouched.
- Fail-open posture throughout: any failure (timeout, DNS, TLS, 5xx, invalid
  key, malformed response) results in the normal Magento page, with a 60s
  circuit breaker and negative caching of misses.
- Bundled AI-crawler registry with daily cron refresh from the keyless
  registry feed, so new crawlers are matched without a module update.
- Local delivery cache (dedicated "Citecue Delivery" cache type) with ETag
  revalidation and configurable local TTL.
- `llms.txt` serving at the store's domain root (llmstxt.org convention),
  gated on both the module toggle and the project setting in Citecue.
- Full-page-cache integration: outermost front-controller plugin plus a
  builtin-FPC bypass for detected crawlers; served responses are
  `Cache-Control: private, no-store`.
- Store-scoped configuration under Stores → Configuration → Citecue →
  AI Delivery, with encrypted API key storage and a Test Connection button
  that lists the organization's projects.
- SSRF hardening: credentialed requests are restricted to HTTPS hosts on the
  admin-managed Allowed API Hosts allowlist; private, loopback, link-local
  and reserved IP-literal hosts are always rejected.
- Excluded-path prefixes (checkout, customer, cart, API and asset paths by
  default) matched on full path segments.
