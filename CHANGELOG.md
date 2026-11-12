# Changelog

All notable changes to `citecue/module-delivery` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
