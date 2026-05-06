<div align="center">

# OpenTrust

**A self-hosted, open-source trust center plugin for WordPress.**

Publish security policies, subprocessors, certifications, and data practices on your own site, with an optional AI assistant grounded in your policies.

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759B.svg)](https://wordpress.org/)
[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/opentrust?style=flat-square)](https://wordpress.org/plugins/opentrust/)
[![Tested WP Version](https://img.shields.io/wordpress/plugin/tested/opentrust?style=flat-square)](https://wordpress.org/plugins/opentrust/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/opentrust?style=flat-square)](https://wordpress.org/plugins/opentrust/advanced/)

</div>

---

OpenTrust is a self-hosted, open-source trust center for WordPress. Procurement teams want a URL they can read. Buyers want receipts. Auditors want a version trail. OpenTrust gives you all three on a branded page that lives on your own WordPress site.

## What's inside

- **Security policies** with auto-incrementing version numbers and archived revisions reachable at stable URLs (`/trust-center/policy/{slug}/version/{n}/`).
- **Subprocessors** with pre-filled metadata for 200+ common cloud vendors and SaaS providers.
- **Compliance certifications** with status badges (active, in progress, expired) and a bundled catalog covering SOC 2, ISO 27001, ISO 27701, HIPAA, PCI-DSS, and others.
- **Data practices** organised by category — the full GDPR Article 30 surface, made public.
- **FAQ** seeded with sensible defaults; edit, add, or remove freely.
- **Contact & DPO block** with company description, DPO name and email, security contact, mailing address, PGP key URL, company registration, VAT/Tax ID. Renders only fields you populate.
- **Optional AI chat** powered by Anthropic, OpenAI, or OpenRouter — agentic retrieval, inline citations, token budgets, rate limits.

## Install

**From WordPress.org**: coming soon at https://wordpress.org/plugins/opentrust/ (pending review).

**Manually:**

1. Download the latest release from [Releases](../../releases).
2. WP Admin → Plugins → Add New → Upload Plugin → upload the zip → Activate.
3. Visit OpenTrust in the admin sidebar to set your accent colour, logo, and company name.
4. Add content under **OpenTrust → Policies / Certifications / Subprocessors / Data Practices**.
5. Visit `/trust-center/` on your site.

## AI chat

Allow users to talk to your policies. AI will cite directly from policies. (Only via Anthropic Citations) 

If you want visitors to be able to ask questions:

1. **OpenTrust → Settings → AI Chat**
2. Pick a provider (Anthropic recommended for citation accuracy), paste an API key (encrypted at rest with libsodium before it touches the database), and pick a model.
3. Set the daily/monthly token budgets you're comfortable with.
4. Optional: enable Cloudflare Turnstile in the same tab for bot defence.
5. Visit `/trust-center/ask/`.

There's no SaaS subscription. You only pay your AI provider for tokens consumed (~$3–$15/month for typical traffic, hard ceilings at 500K tokens/day and 10M tokens/month by default).

## Privacy by design

- **Zero telemetry, zero analytics, zero licence checks.** The only outbound HTTP calls the plugin can make are AI provider requests you configure, and they go through an SSRF host allowlist.
- **No PII in logs.** The optional `wp_opentrust_chat_log` table stores only short hashed identifiers — never raw IPs, emails, sessions, user agents, or referers. The privacy posture is enforced by the schema itself.
- **Encrypted secrets.** API keys and the Cloudflare Turnstile secret are encrypted at rest with libsodium `secretbox`, salted from `wp_salt('auth')`. Rotating `AUTH_KEY` invalidates every stored secret atomically.
- **Theme-isolated rendering.** The trust center intercepts at `template_redirect`, outputs a complete standalone HTML document with inlined CSS, and exits. Your theme's stylesheet, header, footer, and JavaScript never load.
- **Capability-checked admin actions** with nonce verification on every save handler.

## Stack

- **PHP 8.1+** (strict types, match expressions, readonly properties)
- **WordPress 6.0+**
- **libsodium** for secret encryption (bundled with PHP 7.2+)
- **No Composer vendor tree, no build step, no Node.js**
- Vanilla JS for the frontend; jQuery only in admin (a WordPress dependency)
- WPML / Polylang compatible out of the box

## Local development

```bash
git clone https://github.com/nolderoos/opentrust.git
cd opentrust

# Symlink into a local WordPress install (e.g. WP Studio, Local, Lando, etc.)
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/opentrust

# Activate via WP-CLI
wp plugin activate opentrust --path=/path/to/wordpress
```

### Run Plugin Check before submitting changes

```bash
wp plugin check opentrust \
  --categories=plugin_repo,security,performance,general,accessibility \
  --severity=warning \
  --exclude-directories=".claude,.git" \
  --exclude-files="CLAUDE.md,.gitignore,.distignore,.DS_Store"
```

Should report **"No errors found."** Anything else is a regression.

### Build a distribution zip locally

```bash
rsync -a --exclude-from=.distignore --exclude='.git' --exclude='.claude' \
      ./ /tmp/opentrust-stage/opentrust/
cd /tmp/opentrust-stage && zip -rq opentrust.zip opentrust
```

## Translations

Ships with a `.pot` template and a starter Dutch (nl_NL) translation. WPML and Polylang compatible — all four content CPTs are registered public with a `wpml-config.xml` declaring translatable meta fields, so policies, certifications, subprocessors, and data practices can be translated per-language.

Translators can regenerate the template from source:

```bash
wp i18n make-pot . languages/opentrust.pot --domain=opentrust
```

Contribute a translation at [translate.wordpress.org](https://translate.wordpress.org/) once the plugin is live there.

## Contributing

Issues and pull requests welcome. Before opening a PR:

1. Run Plugin Check (above) — it should report zero errors.
2. Verify the plugin still loads cleanly on a fresh WordPress install (`/trust-center/` returns 200, no PHP errors in `debug.log`).
3. If you're adding a user-facing string, wrap it in the `opentrust` text domain.
4. Keep PHP 8.1 as the floor — match expressions and named arguments are fine.

## Status

**1.0.0 — first public release.** Submitted to wordpress.org.

## License

[GPL-2.0-or-later](LICENSE). Same as WordPress core.

## Acknowledgements

Built and maintained by **[Ettic](https://plugins.ettic.nl)**.
