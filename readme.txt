=== OpenTrust ===
Contributors: opentrust
Tags: trust-center, security, compliance, privacy, subprocessors
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.6.0
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
* Subscriber notifications for trust center updates

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

A starter Dutch translation (`nl_NL`) is bundled for the public-facing trust center, chat, and subscribe flows. Other locales fall back to English until translations are provided via translate.wordpress.org or a bundled `.mo` in `languages/`.

Translators can regenerate the template from source with WP-CLI:

`wp i18n make-pot . languages/opentrust.pot --domain=opentrust`

The plugin is compatible with WPML and Polylang: UI strings translate via `.mo` files, and custom post type content (policies, certifications, subprocessors, data practices) can be translated per-language because all four post types are registered as public with a `wpml-config.xml` declaring translatable meta fields.

== Changelog ==

= 0.1.0 =
* Initial release
* Core plugin architecture with 4 custom post types
* Frontend trust center rendering with theme isolation
* Admin settings page with branding options
* Policy versioning and revision history
* Subscriber notification system
