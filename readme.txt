=== OpenTrust ===
Contributors: ettic
Tags: trust-center, compliance, gdpr, privacy, subprocessors
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted trust center: policies, subprocessors, certifications, data practices — with an optional AI assistant grounded in your own corpus.

== Description ==

**OpenTrust is a self-hosted, open-source trust center for WordPress.** Publish your security policies, list your subprocessors, display your compliance certifications, and document your data practices on a single branded page that lives on your own WordPress site. No SaaS subscription. No vendor lock-in. No "phone home."

Procurement teams want a URL they can read. Buyers want receipts. Auditors want a version trail. OpenTrust gives you all three in a plugin, and lets you optionally bolt on an AI assistant that answers visitor questions from your real, published corpus — with citations.

= What you publish =

* **Security policies** with auto-incrementing version numbers, archived revisions, framework citation pills (SOC 2, ISO 27001, GDPR), category grouping, effective dates, and optional PDF attachments.
* **Subprocessors** with purpose, data processed, country, website, and DPA-signed status. Sortable, searchable, and pre-fillable from a bundled catalog of 200+ vendors.
* **Certifications** with status badges (active, in progress, expired), issuing body, issue/expiry dates, and uploadable badge images. Bundled catalog covers SOC 2, ISO 27001, ISO 27701, HIPAA, PCI-DSS, and others.
* **Data practices** organised by category — what you collect, how you store it, who you share it with, your legal basis, your retention period. The full GDPR Article 30 surface, made public.
* **FAQ** seeded with sensible defaults on first activation; edit, add, or remove freely.
* **Contact & DPO block** with company description, DPO name and email, security contact, mailing address, PGP key URL, company registration, and VAT/Tax ID. Renders only fields you fill in.

= Branded, theme-isolated, fast =

* **Standalone rendering.** The trust center intercepts the request at `template_redirect`, outputs a complete standalone HTML document with inlined CSS, and exits. Your theme's stylesheet, header, footer, and JavaScript never load. Zero theme conflicts.
* **CSS scoped via `@layer opentrust`** with every class prefixed `ot-`. Belt-and-braces isolation.
* **WCAG-aware accent colour.** Pick any brand hex; the plugin clamps lightness in HSL space until it clears 4.5:1 contrast on white. Override available if you'd rather take the hit.
* **Locale-aware transient cache** invalidates the moment any OpenTrust post is saved, trashed, restored, or transitions status. No "reindex" button to forget.
* **Auto-incrementing policy versions.** Tick the "new version" box on publish; OpenTrust bumps the number and archives the prior text. Past versions stay reachable at stable URLs (`/trust-center/policy/{slug}/version/{n}/`), so auditors can cite "as of v4" without you digging through revisions.

= Optional AI chat assistant =

Bolt on a trust-center chatbot in five minutes. The AI answers visitor questions from your published corpus only, with inline citations back to the source policy or page.

* **Three providers supported.** Anthropic (recommended; uses native Citations API for verifiable, source-anchored answers), OpenAI, and OpenRouter.
* **Agentic retrieval.** The model reads a slim corpus index in its system prompt and fetches only the documents it needs per question, via two tools (`search_documents` + `get_document`). Pure-PHP BM25 keyword search — no vector store, no external service.
* **SSE streaming** with a "Reading Privacy Policy…" status pill so visitors see what the model is looking at while it answers.
* **Token budgets.** Daily and monthly caps (default 500K/day, 10M/month) with reserve/commit/release accounting. Hit the ceiling and the chat surfaces a graceful exhausted state — never a surprise invoice.
* **Rate limits and bot defence.** Per-IP and per-session sliding windows; optional Cloudflare Turnstile gate with a 1-hour bypass transient.
* **Encrypted secrets.** API keys and the Turnstile secret are encrypted at rest with libsodium `secretbox`, salted from `wp_salt('auth')`. Rotate `AUTH_KEY` and every stored secret invalidates atomically.
* **Zero-PII logging.** Optional `wp_opentrust_chat_log` table stores only short hashed identifiers, the question text (capped at 1,000 chars), and aggregate token counts. A 90-day purge cron keeps it lean. The privacy posture is enforced by the schema itself, not by good intentions.
* **No-JS fallback.** Visitors with JavaScript disabled get a plain HTML form that POSTs and renders the answer server-side.

The chat is fully optional. The plugin works as a static trust center without ever adding an API key.

= Pre-filled libraries =

Type three letters of a vendor name; the rest auto-fills — country, purpose, default category. The bundled catalog covers 200+ subprocessors, common data practices, and the certifications you're likely to list. ~92 KB of metadata, zero network calls, two filters (`opentrust_subprocessor_catalog`, `opentrust_data_practice_catalog`) to extend or replace.

= Translations =

Ships with a `.pot` template and a starter Dutch (nl_NL) translation. WPML and Polylang compatible out of the box — all four content CPTs are registered public with a `wpml-config.xml` declaring translatable meta fields, so policies, certifications, subprocessors, and data practices can be translated per language. Contribute a translation at [translate.wordpress.org](https://translate.wordpress.org/).

= Open source. No upsells. =

GPL-2.0-or-later. Modern PHP 8.1+ codebase with strict types and match expressions. No Composer vendor tree, no build step, no Node dependency. No paid tier, no unlock screens, no feature gating, no "pro add-on." The only variable cost is your AI provider bill *if* you turn the chat on, and that's billed directly by the provider — not by us.

= Privacy-respecting by design =

* **No telemetry, no analytics, no licence checks.** Out of the box, with no API keys configured, the plugin makes zero outbound HTTP calls. The only external services it can contact are the ones you opt into — AI providers and Cloudflare Turnstile — documented in full below.
* **No third-party services required.** All catalogs are bundled. All rendering is local. The Inter variable font (SIL OFL 1.1) is bundled too, so no Google Fonts request leaks visitor IPs to a third party.
* **Capability-checked admin actions** with nonce verification on every save handler.
* **Hashed identifiers** for any rate-limit or log state — never raw IPs, emails, sessions, user agents, or referers.

== External services ==

OpenTrust is opt-in for every external request. Out of the box, with no API keys or Turnstile keys configured, the plugin makes zero outbound HTTP calls. The services below are contacted only after you explicitly enable the corresponding feature in **OpenTrust → Settings → AI Chat**. All outbound URLs are restricted by an SSRF host allowlist.

= AI providers (one of: Anthropic, OpenAI, OpenRouter) =

Triggered when a visitor submits a question on `/trust-center/ask/`, after you have configured an API key. Each chat turn sends the visitor's question, a slim corpus index of your published trust-center content plus the specific documents the model retrieves via tool calls, and the conversation history within that session. No visitor PII is forwarded — only the question text the visitor types.

* **Anthropic** — Endpoint: `https://api.anthropic.com/v1/messages`. Terms: https://www.anthropic.com/legal/commercial-terms. Privacy: https://www.anthropic.com/legal/privacy.
* **OpenAI** — Endpoint: `https://api.openai.com/v1/chat/completions`. Terms: https://openai.com/policies/business-terms. Privacy: https://openai.com/policies/privacy-policy.
* **OpenRouter** — Endpoint: `https://openrouter.ai/api/v1/chat/completions`. Terms: https://openrouter.ai/terms. Privacy: https://openrouter.ai/privacy.

= Cloudflare Turnstile (optional bot defence) =

Triggered when you enable Turnstile in **OpenTrust → Settings → AI Chat** by entering site and secret keys. The chat page loads a small JavaScript challenge widget from Cloudflare; the resulting token is verified server-side on the first message of each visitor session. No personal data is transmitted by OpenTrust — the token itself is opaque.

* **Widget script:** `https://challenges.cloudflare.com/turnstile/v0/api.js`
* **Token verification:** `https://challenges.cloudflare.com/turnstile/v0/siteverify`
* **Terms:** https://www.cloudflare.com/website-terms/
* **Privacy:** https://www.cloudflare.com/privacypolicy/

== Installation ==

= Quick start (5 minutes, no AI) =

1. Install from the WordPress plugin directory, or upload the `opentrust` folder to `/wp-content/plugins/` and activate.
2. Go to **OpenTrust → Settings**. Set your company name, page title, tagline, accent colour, and upload a logo.
3. Add content under each menu item:
   * **OpenTrust → Policies** — write or paste your security policies.
   * **OpenTrust → Certifications** — list your active and in-progress certifications.
   * **OpenTrust → Subprocessors** — start typing a vendor name; the catalog auto-fills the rest.
   * **OpenTrust → Data Practices** — document what you collect and how it's handled.
4. Visit `/trust-center/` on your site. That's your live trust center.

= Optional: AI chat (5 more minutes) =

1. Go to **OpenTrust → Settings → AI Chat**.
2. Pick a provider (Anthropic recommended for citation accuracy), paste your API key, and pick a model. The key is encrypted before it touches the database.
3. Save. Set the daily/monthly token budgets you're comfortable with.
4. Visit `/trust-center/ask/` to test, or link to it from your trust center hero.
5. Optional: enable Cloudflare Turnstile in the same tab if you want bot defence on top of the rate limits.

= Requirements =

* WordPress 6.0 or higher
* PHP 8.1 or higher
* libsodium (bundled with PHP 7.2+) for secret encryption — already present on virtually every modern host

== Frequently Asked Questions ==

= Is OpenTrust really free? =

Yes. GPL-2.0-or-later with no paid tier, no unlock screens, no feature gating, no "pro add-on" upsell. Install it, host it, ship it, for as long as WordPress keeps running. The only variable cost is your AI provider bill *if* you enable the chat — billed directly by the provider, never by us.

= Do I have to enable the AI chat? =

No. The plugin works as a fully static trust center without ever adding an API key — policies, certifications, subprocessors, data practices, FAQ, contact block. The AI assistant is an additive feature; flip it on when you're ready, flip it off any time.

= What does running the chat actually cost? =

Pocket change for most sites. You only pay your AI provider directly for the tokens consumed, and the agentic retrieval engine fetches just the documents needed per question instead of dumping the whole corpus on every request. Ballpark, on Anthropic Claude Sonnet at current pricing:

* **Quiet** (~50 conversations/month): under $3/month.
* **Typical** (~200 conversations/month): $8–$15/month.
* **Busy** (~1,000 conversations/month): $40–$60/month, near the default monthly cap.

Hard ceilings are 500K tokens/day and 10M tokens/month, enforced by a reserve/commit/release budget. Tune them to your appetite. Once a cap is hit, visitors see a graceful "come back later" state — never a surprise bill.

= What stops someone burning through my AI credits? =

Three overlapping defences. Token budgets are hard ceilings, not soft hints. Per-IP (60s) and per-session (1h) sliding-window rate limits keep one visitor from flooding the queue. Optional Cloudflare Turnstile gates the first message of every session, with a 1-hour bypass transient so repeat readers aren't pestered.

= Does the AI stay in sync when I update a policy? =

Yes, automatically. The corpus the model sees is cached as a transient and invalidated the moment any OpenTrust post is saved, trashed, restored, or transitions status. Even if nothing changes, the cache expires after 12 hours. There is no "reindex" button to forget.

= Does the plugin phone home? =

No. Zero telemetry, zero analytics, zero licence checks. Out of the box, with no API keys configured, the plugin makes zero outbound HTTP calls. The only services it can contact are the ones you opt into — AI providers (when you add a chat API key) and Cloudflare Turnstile (when you enable it) — both fully documented in the **External services** section above and constrained by an SSRF host allowlist. Even fonts are bundled locally; nothing leaks to Google Fonts.

= What do chat logs store about visitors? =

Structurally, never PII. The `wp_opentrust_chat_log` table has no columns capable of holding raw IPs, emails, session IDs, user agents, or referers — only short hashed identifiers, the question text (capped at 1,000 chars), and aggregate token counts. A 90-day purge runs on `wp_cron`. The privacy posture is enforced by the schema itself, not by good intentions. Logging can also be disabled entirely.

= Will it clash with my theme? =

It can't. The trust center intercepts the request at `template_redirect`, outputs a complete standalone HTML document with inlined CSS, and exits. Your theme's stylesheet, header, footer, and JavaScript never load. All styles are wrapped in `@layer opentrust` and prefixed with `ot-` for belt-and-braces isolation.

= Is there an audit trail for policy changes? =

Yes. Tick the "new version" box on publish; OpenTrust bumps the version number and archives the prior text as a WordPress revision. Each historical version is reachable at a stable URL (`/trust-center/policy/{slug}/version/{n}/`), so auditors can cite "as of v4" without you digging through revisions. Buyers see "last updated" on the current policy; auditors get the receipts.

= How hard is it to brand? =

Pick a hex accent colour, upload a logo, set a page title and tagline. That's the whole setup surface for look-and-feel. The plugin clamps your accent's lightness in HSL space until it clears WCAG AA contrast on white, or honours your override if you'd rather take the hit.

= Is it translatable? =

Yes. Ships with a `.pot` template and a starter Dutch (nl_NL) translation. WPML and Polylang compatible out of the box. All four content CPTs are registered public with a `wpml-config.xml` declaring translatable meta fields. Translators can regenerate the template with WP-CLI:

`wp i18n make-pot . languages/opentrust.pot --domain=opentrust`

= What's the minimum stack? =

PHP 8.1+, WordPress 6.0+. No Composer vendor tree, no build step, no Node dependency. Libsodium (bundled with PHP 7.2+) is used for secret encryption. That's the whole stack.

= Does it generate PDFs? =

Not automatically — that's intentional. Auto-rendered PDFs from HTML almost always look worse than the source, and most legal teams prefer a hand-crafted master copy anyway. If you want a PDF download next to a policy, upload your authoritative version via the media library and OpenTrust shows the Download button. No PDF? No button.

== Screenshots ==

1. The public trust center — hero, policies grouped by category, certifications grid, all branded with your accent colour.
2. The Subprocessors table — sortable, searchable, with country, purpose, data processed, and DPA status.
3. Compliance certifications with status badges (active, in progress, expired).
4. Data practices organised by category — collection, storage, sharing, legal basis, retention.
5. AI chat assistant with inline citations, status pill, and source-anchored answers.
6. Settings screen with logo upload, accent colour picker, and live WCAG contrast warning.

== Changelog ==

= 1.0.0 =
* Initial public release.
