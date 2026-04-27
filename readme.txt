=== OpenTrust ===
Contributors: ettic
Tags: trust-center, security, compliance, privacy, subprocessors
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted, open-source trust center for publishing security policies, subprocessors, certifications, and data practices.

== Description ==

OpenTrust provides a centralized, branded trust center page for your WordPress site. Similar to commercial solutions like Vanta Trust Center, Drata, or SafeBase, it gives companies a professional page to communicate their security posture.

**Features:**

* Publish and version security policies with full revision history
* List subprocessors with purpose, data processed, country, and DPA status
* Display compliance certifications with status tracking (active, in progress, expired)
* Document data practices grouped by category with collection/storage/sharing details
* Standalone, theme-isolated rendering with customizable accent colors
* Built-in search and sortable tables
* Print-friendly policy views

== Installation ==

1. Upload the `opentrust` directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to OpenTrust > Settings to configure your company name, logo, and accent color
4. Add content via the OpenTrust custom post types (Policies, Certifications, Subprocessors, Data Practices)
5. Visit `/trust-center/` on your site to see the public trust center

== Frequently Asked Questions ==

= Does this require any third-party services? =

No. OpenTrust is fully self-hosted. All data stays in your WordPress database.

= Can I customize the appearance? =

Yes. You can set your company logo, accent color, and tagline in the settings. The trust center renders as a standalone page with its own design system, fully isolated from your theme.

= How does policy versioning work? =

Each time you update and publish a policy, OpenTrust automatically increments the version number and preserves the previous version as a revision. Visitors can view older versions and see what changed.

= Is OpenTrust translatable? =

Yes. OpenTrust is fully internationalized and ships with a translation template at `languages/opentrust.pot`. The plugin automatically loads translations for the active site language (Settings > General > Site Language) — no configuration needed.

A starter Dutch translation (`nl_NL`) is bundled for the public-facing trust center and chat flows. Other locales fall back to English until translations are provided via translate.wordpress.org or a bundled `.mo` in `languages/`.

Translators can regenerate the template from source with WP-CLI:

`wp i18n make-pot . languages/opentrust.pot --domain=opentrust`

The plugin is compatible with WPML and Polylang: UI strings translate via `.mo` files, and custom post type content (policies, certifications, subprocessors, data practices) can be translated per-language because all four post types are registered as public with a `wpml-config.xml` declaring translatable meta fields.

== Changelog ==

= 0.9.6 =
* Chat: pill label now switches verb tense when it settles ("Searching for X" → "Searched for X", "Reading X" → "Read X"). Previously only multi-turn flows morphed the label; single-turn searches stayed in active tense forever after the answer finished.
* Chat: between-turn model preambles ("Let me search more specifically…") are now locked as their own segment in the conversation instead of being mashed into the final answer body. The pill stays as a single morphing entity; preambles flow above and below it in chronological order.
* Chat: removed the blinking caret at the tail of streaming answers — the typewriter cadence already signals "still typing" without a separate cursor element.
* No database schema change.

= 0.9.5 =
* Chat (Anthropic): added an early `tool_intent` SSE event so the status pill appears within ~1-2 seconds of submit instead of waiting 6-8 seconds for the model to finish generating all parallel tool inputs. The pill starts with a generic label ("Searching documents…" / "Reading documents…") and morphs to the specific count ("Reading 11 documents") as soon as the model finishes its planning. OpenAI / OpenRouter providers unchanged for now — their SSE format does not expose the same fine-grained signal.
* No database schema change.

= 0.9.4 =
* Chat: fixed the tool-use pill appearing in its settled state with no animation. On cached prompts the entire SSE burst (tool_call → tokens → done) lands in one task tick, so the pill DOM was being created after `done` had already locked it. The pill now renders synchronously the moment `tool_call` arrives, with its dots pulsing for the full minimum-duration window before settling.
* Chat: the answer body now stays paused on the pill for the full 1.2s minimum window even when `done` arrives within the same task tick. Previously the answer would race in alongside the pill on fast cached responses; now the visitor reads what the model is doing first, then sees the answer.
* No database schema change.

= 0.9.3 =
* Chat: parallel tool calls now collapse into a single morphing status pill ("Reading 8 documents") instead of stacking N pills. The pill updates its label as the model fires more retrievals and settles with a count summary ("Searched 8 documents") when the answer completes.
* Chat: fixed a render-loop bug where the pill's entry animation restarted on every typewriter tick, causing the dots to flicker at low opacity until the message finished. Prior message segments now render once and stay; only the live tail re-renders per frame.

= 0.9.2 =
* Chat: the model now writes one short intent sentence before each tool call ("Let me check our subprocessors list") to give the response a more conversational feel. Capped at 12 words, suppressed when the question itself makes the intent obvious.

= 0.9.1 =
* Chat: tool-use status pill is now rendered inline within the message at the position it happened, instead of below the response. Pills persist as a record of what the model retrieved (Claude / ChatGPT / Perplexity-style).
* Chat: when the model is mid-retrieval, the answer text visibly pauses at the pill for at least 1.2 seconds so the visitor can read what's being looked up. Cached prompts no longer make the indicator flash too fast to read.
* Chat: suppressed the model's pre-tool-call preambles ("Let me check…", "I'll look that up…"). The pill replaces that signal; cleaner output, slightly cheaper.
* No database schema change.

= 0.9.0 =
* Replaced the chat engine with agentic retrieval. The AI now reads a compact index of your trust center and fetches only the documents it needs to answer each question — instead of receiving every document on every request. Per-question API spend drops by ~80% on Anthropic, and the chat now works on free-tier and Tier 1 accounts that previously hit rate limits.
* Removed the 120K-token corpus size cap. Installs with hundreds of policies and subprocessors now work without manual configuration.
* Added an optional AI-generated summary for each policy. Improves answer quality on multi-policy installs by helping the AI route synonym questions ("data deletion") to the right document ("Data Retention Policy"). Off by default; enable on the AI Chat settings tab. One-time cost approximately $0.05–$0.10 per 50 policies.
* Added a "Looking up …" indicator while the AI fetches documents.
* Database schema bumped to v10. Additive only — the chat-log table grows two columns (tool_turns, tool_names); no rollback step needed.
* All three providers (Anthropic, OpenAI, OpenRouter) migrated to the agentic engine. Anthropic Sonnet 4.5 remains the recommended provider for best citation accuracy.

= 0.8.0 =
* Added Policy ID reference field (e.g., POL-012) visible on the public listing and single-policy view.
* Added framework citation repeater (SOC 2, ISO 27001, GDPR) rendered as pill badges.
* Replaced the print-to-PDF stub with a real PDF attachment field. If the author uploads a PDF, visitors see a Download button; otherwise the download is hidden.
* Refactored the public policy list from a table into a category-grouped document list with filter chips.
* Curated the policy block-editor palette to a focused set (paragraph, heading, list, table, quote, separator, image, code, details) with a starter template.
* DB schema bumped to v8: the deprecated `_ot_policy_downloadable` meta is deleted on upgrade; `/policy/{slug}/pdf/` rewrite rule removed.

= 0.7.0 =
* Subscription and email-broadcast feature moved to the feature/subscriptions-broadcasts branch so we can ship a tighter launch surface.
* Database schema bumped to v7: the `opentrust_subscribers` and `opentrust_notification_log` tables are dropped on upgrade.

= 0.1.0 =
* Initial release
* Core plugin architecture with 4 custom post types
* Frontend trust center rendering with theme isolation
* Admin settings page with branding options
* Policy versioning and revision history
