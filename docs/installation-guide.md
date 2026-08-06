# Citecue AI Delivery for Magento 2 — Installation Guide

Extension: `Citecue_Delivery` (Composer package `citecue/module-delivery`)

## Requirements

- Magento Open Source / Adobe Commerce **2.4.4 – 2.4.x**
- PHP **8.1, 8.2, 8.3 or 8.4**
- A Citecue account with:
  - an **organization API key** (`ck_live_…`), and
  - a **project** whose domain matches your store (its **public key** is entered in Magento).

No database schema changes are made; the module only adds configuration, a
dedicated cache type, and a cron job.

## Install via Composer (recommended)

From your Magento project root:

```bash
composer require citecue/module-delivery
bin/magento module:enable Citecue_Delivery
bin/magento setup:upgrade
bin/magento setup:di:compile        # required in production mode
bin/magento setup:static-content:deploy   # production mode only
bin/magento cache:flush
```

If you purchased the extension on the Adobe Commerce Marketplace, make sure
your `repo.magento.com` credentials are configured (`auth.json`), then run the
commands above.

## Install manually (app/code)

1. Extract the extension archive to `app/code/Citecue/Delivery/` so that
   `app/code/Citecue/Delivery/registration.php` exists.
2. Run:

```bash
bin/magento module:enable Citecue_Delivery
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

## Verify the installation

```bash
bin/magento module:status Citecue_Delivery
```

should list the module as enabled. In the Admin you should see:

- **Stores → Configuration → Citecue → AI Delivery** (new configuration section)
- **System → Cache Management** → a **Citecue Delivery** cache type

## Configure

See the User Guide for full details. Minimum setup:

1. Open **Stores → Configuration → Citecue → AI Delivery**.
2. Set **Enabled** = Yes.
3. Paste your organization **API Key** (`ck_live_…`).
4. Click **Test Connection**, then click **Use this key** next to the project
   matching this store's domain, and **Save Config**.

## Using Varnish or a CDN?

Varnish (and CDN caches) answer cache hits before Magento runs. Add a pass for
AI crawlers in `vcl_recv` so they always reach the middleware:

```vcl
if (req.http.User-Agent ~ "(?i)(GPTBot|OAI-SearchBot|ChatGPT-User|ClaudeBot|Claude-User|Claude-SearchBot|anthropic-ai|PerplexityBot|Perplexity-User|GoogleOther|Bytespider|CCBot|cohere-ai|meta-externalagent|meta-externalfetcher|Amazonbot|DuckAssistBot|MistralAI-User)") {
    return (pass);
}
```

The equivalent applies to Fastly/Cloudflare cache rules: bypass caching for
these user agents.

## Upgrade

```bash
composer update citecue/module-delivery
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Disable / uninstall

```bash
bin/magento module:disable Citecue_Delivery
bin/magento setup:upgrade
bin/magento cache:flush
```

To remove the Composer package afterwards:

```bash
composer remove citecue/module-delivery
bin/magento setup:upgrade
bin/magento cache:flush
```

Magento does not delete configuration on uninstall, so the module's settings
— including the encrypted organization API key — remain in
`core_config_data`. To fully clean up, either clear the **API Key** field and
save *before* uninstalling, or remove the rows afterwards:

```sql
DELETE FROM core_config_data WHERE path LIKE 'citecue_delivery/%';
```

If the key should no longer be used at all, also revoke it in the Citecue
dashboard (**Organization → API keys**).

The module is fail-open by design: disabling it (or removing the API key)
simply restores stock Magento behavior for every visitor.

## Support

- Issues: https://github.com/henry-mosh/citecue-magento/issues
- Citecue dashboard & documentation: https://app.citecue.com
