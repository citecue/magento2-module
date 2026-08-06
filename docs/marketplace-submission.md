# Adobe Commerce Marketplace — Submission Checklist

State of the repo: **ready for technical review**. This file tracks what is
already done and the manual steps only a human with the Citecue accounts can
do.

## Already done (in this repo)

- [x] `composer.json` complete for Marketplace: lowercase `citecue/module-delivery`,
      `type: magento2-module`, `version: 1.0.0` (required for ZIP submissions),
      MIT license, authors/support/homepage, PHP `8.1–8.4`, Magento 2.4.4+ deps.
- [x] `registration.php` + `etc/module.xml` consistent (`Citecue_Delivery`).
- [x] **Magento Coding Standard: 0 errors, 0 warnings** (`vendor/bin/phpcs
      --standard=Magento2`, the standard EQP runs). PHPCompatibility clean for
      PHP 8.1–8.4. `phpcs.xml.dist` committed for CI parity.
- [x] Unit tests green (18 tests / 44 assertions, framework-free).
- [x] API key declared as sensitive config (`etc/di.xml` TypePool) — excluded
      from `app:config:dump`.
- [x] LICENSE (MIT), README, CHANGELOG.md (source of release notes text).
- [x] Admin ACL, CSRF-safe admin controller (`HttpPostActionInterface` +
      form key), escaped templates, encrypted key storage, SSRF host
      allowlist — the security areas EQP reviews manually.
- [x] Submission ZIP built and structurally validated:
      `dist/citecue_module-delivery-1.0.0.zip`
      (composer.json + registration.php at ZIP root, no VCS/dev files).

Rebuild the ZIP after any change:

```bash
cd /path/to/module
rm -rf /tmp/citecue-pkg && mkdir -p /tmp/citecue-pkg
rsync -a --exclude='.git*' --exclude='docs' --exclude='dist' \
      --exclude='phpcs.xml.dist' --exclude='.DS_Store' ./ /tmp/citecue-pkg/
cd /tmp/citecue-pkg && zip -rq citecue_module-delivery-1.0.0.zip . -x '.*'
```

(The `rm -rf` matters: reusing a stale staging directory would carry files
you have since deleted from the module into the new ZIP.)

## Manual steps (Developer Portal)

1. **Account** — register at the
   [Commerce Developer Portal](https://developer.adobe.com/commerce/marketplace/guides/sellers/)
   (developer.adobe.com → Commerce Marketplace → Sell). Complete the profile:
   - Vendor display name (e.g. "Citecue").
   - **Composer vendor prefix must be `citecue`** — it has to match the
     package name `citecue/module-delivery`.
   - Payment/tax info only if the extension will be paid.
2. **Create the product** — "Extension" listing, platform Magento Open
   Source / Adobe Commerce, versions **2.4.4–2.4.8** (tick the ones you
   support), PHP 8.1–8.4.
3. **Upload the package** — `dist/citecue_module-delivery-1.0.0.zip`.
   The portal runs the EQP automated checks (malware scan, coding standard,
   installability, plagiarism/copy-paste) and then manual QA.
4. **Marketing content** (prepare before submitting; reviewed separately):
   - **Name**: e.g. "Citecue AI Delivery — serve AI-optimized pages to AI
     crawlers".
   - **Short & long description**: source from README "What it does" /
     "How it works". Plain-language, no competitor names, no pricing in text.
   - **Icon**: 360×360 PNG on transparent/solid background (Citecue logo).
   - **Gallery images**: at least 1, 1280×720 PNG/JPG. Recommended set:
     admin configuration screen, Test Connection result with project list,
     Citecue Agent Traffic dashboard, a diagram of crawler vs. human flow.
   - **User Guide**: export `docs/user-guide.md` to PDF (or host it and give
     the URL).
   - **Installation Guide**: export `docs/installation-guide.md` to PDF or URL.
   - **Release notes**: paste the 1.0.0 section from `CHANGELOG.md`.
   - **Categories**: e.g. Marketing → SEO/SEM; Site Optimization.
   - **Support**: email/URL + link to
     https://github.com/henry-mosh/citecue-magento/issues.
   - **Pricing**: free or paid (+ optional support/installation services).
5. **Submit for review** and watch the portal inbox — reviewers typically
   respond within a few business days; failed checks come back with a report
   you can fix and resubmit against the same version.

## Points reviewers may raise (answers ready)

- **Why does the extension call an external API?** Core function: it fetches
  the AI-optimized page variant from the merchant-configured Citecue account.
  Documented in README + guides; keyless calls are limited to the public
  crawler-registry feed. The data transmitted is the crawler-requested URL
  (including its query string, which in principle can carry identifying
  parameters from crawled links) and the crawler id — no customer accounts,
  session, order or payment data. Only requests from detected AI crawlers
  ever trigger an API call, and checkout/customer/cart/API paths are
  excluded by default.
- **"Cloaking" concern**: only self-declared AI crawlers (not Googlebot/
  Bingbot or users) receive the optimized variant, which is derived from the
  merchant's own content; responses are `private, no-store` so shared caches
  can never leak the variant to humans.
- **Two `phpcs:ignore` annotations** (`Model/Config.php`,
  `Model/DeliveryService.php`): documented inline — pure, dependency-free
  SSRF-validation helpers and `parse_url` for log redaction.

## Verification commands (what EQP will effectively run)

```bash
# Coding standard (must be error-free; this codebase is 0/0):
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml,xml /path/to/module

# Install test in a clean Magento 2.4.x:
composer require citecue/module-delivery
bin/magento module:enable Citecue_Delivery && bin/magento setup:upgrade
bin/magento setup:di:compile && bin/magento setup:static-content:deploy -f
bin/magento cache:flush

# Unit tests (inside the Magento installation):
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Citecue/Delivery/Test/Unit
```
