# SEO Internal Linker

A WordPress plugin that automatically turns the **first occurrence** of a defined phrase in a post into a link to a target page, and generates a friendly, SEO/AEO-optimised **TL;DR summary** at the top of each post via the Claude API — all without touching render time.

**Created by [Elad Aybes](https://github.com/eaybes).**

---

## Table of Contents

- [Features](#features)
- [How It Works](#how-it-works)
- [Installation](#installation)
- [Usage](#usage)
  - [Managing Phrases](#managing-phrases)
  - [Settings](#settings)
  - [TL;DR Auto-Summary](#tldr-auto-summary)
  - [Per-Post / Per-Page Controls](#per-post--per-page-controls)
  - [Rescanning Existing Content](#rescanning-existing-content)
- [Matching Rules](#matching-rules)
- [Security](#security)
- [Database Schema](#database-schema)
- [Hooks Reference](#hooks-reference)
- [FAQ](#faq)
- [Changelog](#changelog)
- [License](#license)

---

## Features

**Internal linking**
- **Automatic first-occurrence linking** — define a phrase and a target URL once; the plugin links the first plain-text appearance of that phrase in each post.
- **Runs on posts by default** — enabled automatically for the `post` post type.
- **Opt-in for pages** — disabled by default on `page`, with a per-page checkbox to enable it.
- **Per-post opt-out** — disable automatic linking on an individual post via a checkbox in the editor sidebar.
- **Per-phrase override per post** — uncheck any phrase in the post meta box to stop it being linked on that specific post, without affecting any other post.
- **Manual-unlink detection** — if an editor manually deletes an auto-inserted link, the plugin detects this on the next save and adds that phrase to the skip list so it won't snap back.
- **Skips headings and existing links** — a phrase already inside an `<h1>`–`<h6>` or any existing `<a>` counts as "used" and won't receive a duplicate link.
- **Live usage statistics** — each phrase tracks how many posts currently carry an auto-generated link to it, updated in real time as content is created, edited, or deleted.
- **Configurable link behavior** — optionally open auto-inserted links in a new tab (`target="_blank" rel="noopener noreferrer"`).

**TL;DR Auto-Summary**
- **AI-generated summaries** — on post save, the plugin calls the Claude API to produce up to 5 friendly, keyword-rich bullet points summarising the article.
- **SEO + AEO optimised** — bullets are written as standalone, complete sentences designed to rank in search results and surface in voice search / AI answer engines.
- **Schema.org markup** — the TL;DR box renders as an `ItemList` with `itemscope`/`itemprop` attributes giving structured-data signals to search engines.
- **Opt-in for pages** — TL;DR is on by default for posts and can be enabled per-page via the editor sidebar.
- **Stored in post meta** — TL;DR content lives in `_sil_tldr_bullets`, keeping it completely separate from `post_content` so it never interferes with the internal linker.
- **Fully editable** — clear and regenerate via the editor sidebar's "Clear TL;DR" button; the next save will regenerate from the latest content.
- **Graceful fallback** — if no API key is configured, TL;DR generation is silently skipped with no errors.

**General**
- **RTL / Hebrew-safe** — all string matching and DOM manipulation uses multibyte-safe functions.
- **Batched background rescanning** — re-apply the phrase list to all existing content via WP-Cron in batches of 20, without timing out on large sites.
- **Hardened against malicious input** — URLs restricted to `http`/`https` at multiple layers; all output escaped; every action nonce- and capability-protected.

---

## How It Works

1. You define **phrase → target URL** pairs in the plugin's admin screen.
2. When a post (or enabled page) is saved:
   - The plugin parses the content into a DOM tree and links the **first plain-text occurrence** of each phrase (longest-first to avoid partial matches).
   - Occurrences inside headings or existing links are counted as "used" — no second link is added.
   - The rewritten `post_content` is stored permanently in the database.
3. If an Anthropic API key is configured, the plugin also calls the Claude API and stores up to 5 TL;DR bullet points in post meta.
4. On the front end, the TL;DR box is injected at the top of the content via the `the_content` filter (no `post_content` is modified), styled with a small inline CSS block.

---

## Installation

1. Upload or clone the plugin folder into `wp-content/plugins/seo-internal-linker/`.
2. In **WordPress Admin → Plugins**, find **SEO Internal Linker** and click **Activate**.
   - On activation, the plugin creates `wp_sil_phrases` in the database.
3. A new **Internal Linker** menu item appears in the WordPress admin sidebar.

### Installing on additional sites

Copy the plugin folder (or upload the ZIP via **Plugins → Add New → Upload Plugin**) to any WordPress install and activate normally. Phrases are stored per-site so you'll need to re-enter them on each new site (or migrate the `wp_sil_phrases` table directly).

---

## Usage

### Managing Phrases

Go to **WordPress Admin → Internal Linker**:

- **Add new phrase** — enter the exact phrase text and a target URL (`http://` or `https://` only).
- **Existing phrases** table — shows each phrase, its target, and the **Active links** count (how many posts currently contain an auto-generated link to it).
- **Edit / Delete** are available per row.

> **💡 Tip:** Choose your anchor text strategically. Use clear, specific phrases instead of broad words or just brand names. Pick phrases that describe the linked page well and have a good chance of ranking in search results.

### Settings

Under **Settings** on the same page:

| Setting | Description |
|---|---|
| Open links in new tab | Adds `target="_blank" rel="noopener noreferrer"` to all auto-inserted links. |
| TL;DR section heading | Label shown at the top of every TL;DR box (default: `TL;DR 😎`). |
| Anthropic API key | Used to call the Claude API for TL;DR generation. Leave blank to disable. The field is a password input that preserves the existing key if submitted empty. |

### TL;DR Auto-Summary

Once an Anthropic API key is entered:

- Every time a post is saved, the plugin sends the post title and content (up to ~700 words) to the Claude API and stores up to 5 bullet points in post meta.
- Bullets appear in a styled, bordered box right at the start of the post content.
- The box includes `schema.org/ItemList` structured data for SEO/AEO.
- You can preview the current bullets in the post editor's **SEO Internal Linker** sidebar meta box.
- Click **Clear TL;DR** in the sidebar to delete the stored bullets; the next save will regenerate them.

### Per-Post / Per-Page Controls

The **SEO Internal Linker** meta box in the editor sidebar contains:

**Internal linking**
- *Pages*: checkbox to enable auto-linking (off by default).
- *Posts*: checkbox to disable auto-linking (on by default).
- Phrase checklist: uncheck any phrase to skip it on this specific post/page. Manually removed links are added here automatically.

**TL;DR Summary**
- *Pages*: checkbox to enable TL;DR (off by default).
- *Posts*: checkbox to disable TL;DR (on by default).
- Preview of current bullet points + **Clear TL;DR** button.

### Rescanning Existing Content

After adding, editing, or deleting phrases, existing posts won't be updated until you rescan:

1. Click **Rescan all content now** on the plugin's admin page.
2. The plugin queues all eligible posts/pages and processes them in batches of 20 via WP-Cron (no timeout risk on large sites).
3. Progress (`X of Y processed`) is shown on the admin page — reload to refresh.

> Note: the rescan re-runs internal linking only. TL;DR bullets are regenerated on each individual post save, not by the rescan.

---

## Matching Rules

- Matching is **exact and case-sensitive** — the phrase must appear verbatim.
- Only the **first** occurrence per post is linked; subsequent occurrences are left untouched.
- A phrase inside an existing `<a>` tag or inside a heading (`<h1>`–`<h6>`) counts as "first occurrence" and prevents a second link being added elsewhere.
- Phrases are processed **longest-first** so a longer phrase containing a shorter one (e.g. "climate crisis" vs. "climate") is matched before the shorter substring can claim it.
- `<script>` and `<style>` content is never touched.

---

## Security

- **URL scheme allowlisting**: target URLs are validated to `http`/`https` only via `esc_url_raw()` with an explicit protocol allowlist — blocks `javascript:`, `data:`, `vbscript:`, etc.
- **Defense in depth at render time**: even if an unsafe URL somehow reaches the database, `wrap_occurrence()` re-validates via `esc_url()` and silently skips the link rather than writing unsafe markup.
- **Capability checks**: phrase CRUD and settings require `manage_options`; per-post/page toggles require `edit_post`.
- **CSRF protection**: every state-changing action is protected with a WordPress nonce.
- **Output escaping**: all admin output uses `esc_html()`, `esc_attr()`, `esc_url()`, and `esc_js()` throughout.
- **Input sanitization**: phrase text has tags stripped and whitespace normalized; length is capped to match the database column.
- **XXE protection**: the DOM parser uses `LIBXML_NONET` and explicit entity-loader hardening (PHP < 8 only; PHP 8+ disables external entities by default).
- **Safe redirects**: all post-action redirects use `wp_safe_redirect()`.
- **No unsolicited remote calls**: the only external request is to the Anthropic API, which only fires when you have explicitly configured an API key.

---

## Database Schema

### `{$wpdb->prefix}sil_phrases`

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key, auto-increment |
| `phrase` | `VARCHAR(255)` | Unique |
| `target_url` | `VARCHAR(2048)` | Validated to `http`/`https` |
| `usage_count` | `BIGINT UNSIGNED` | Posts currently linking to this phrase |
| `created_at` | `DATETIME` | |

Schema upgrades are versioned and applied automatically on `plugins_loaded` — no deactivate/reactivate required.

### Post meta keys

| Meta key | Used on | Purpose |
|---|---|---|
| `_sil_disabled` | posts | `'1'` disables auto-linking on this post |
| `_sil_enabled` | pages | `'1'` enables auto-linking on this page |
| `_sil_skip_phrases` | posts/pages | Array of phrase IDs excluded on this content |
| `_sil_active_phrases` | posts/pages | Array of phrase IDs currently auto-linked here |
| `_sil_tldr_disabled` | posts | `'1'` disables TL;DR generation on this post |
| `_sil_tldr_enabled` | pages | `'1'` enables TL;DR generation on this page |
| `_sil_tldr_bullets` | posts/pages | Stored array of TL;DR bullet strings |

---

## Hooks Reference

| Hook | Type | Fired by | Purpose |
|---|---|---|---|
| `save_post` | action | WordPress core | Triggers relinking (priority 20) and TL;DR generation (priority 25) |
| `the_content` | filter | WordPress core | Injects the TL;DR box at the top of post content |
| `wp_head` | action | WordPress core | Outputs TL;DR box CSS on single post/page views |
| `before_delete_post` | action | WordPress core | Releases usage counts when a post is permanently deleted |
| `sil_process_rescan_batch` | action | WP-Cron (this plugin) | Processes the next batch of the rescan queue |

---

## FAQ

**Does this change my content permanently?**
Internally-linked `<a>` tags are written into `post_content` and stored in the database — you'll see them in the editor. TL;DR bullets are stored separately in post meta and injected via `the_content` filter, never modifying `post_content` itself.

**What happens if I change a phrase's target URL?**
Existing posts keep the old link until you click **Rescan all content now**.

**What if I don't want a specific auto-link on one post?**
Open that post, find the **SEO Internal Linker** sidebar meta box, and uncheck the phrase. Alternatively, just delete the `<a>` tag manually — the plugin detects this and won't re-add it on the next save.

**Can I customise the look of the TL;DR box?**
Add CSS targeting `.sil-tldr-box`, `.sil-tldr-title`, and `.sil-tldr-box li` in your theme. The plugin's inline styles are intentionally minimal so they're easy to override.

**Which Claude model is used for TL;DR?**
`claude-haiku-4-5` — the fastest and most cost-effective Claude model, well-suited for short summarisation tasks.

**Does it work with Hebrew/RTL content?**
Yes — all matching and DOM rewriting is multibyte-safe and was specifically tested with Hebrew text.

**Will it slow down my site?**
Internal linking happens once on save, not on every page view. TL;DR generation is a single API call on save (~1–3 s), also invisible to visitors.

---

## Changelog

### 1.1.0
- **New**: TL;DR auto-summary — generates up to 5 SEO/AEO-optimised bullet points via the Claude API on post save, displayed in a styled, schema-marked box at the top of each post.
- **New**: TL;DR settings (section heading, Anthropic API key) in the plugin admin screen.
- **New**: Per-post opt-out / per-page opt-in for TL;DR, with bullet preview and "Clear TL;DR" button in the editor sidebar.
- **New**: SEO anchor text tip displayed below the phrase form in the admin screen.
- **Improved**: settings now preserve the existing API key when the password field is submitted empty.

### 1.0.0
- Initial release: phrase management, automatic first-occurrence linking, per-post/per-page controls, batched rescanning, live usage statistics, and security hardening.

---

## License

GPL v2 or later, consistent with WordPress plugin licensing conventions.

---

Maintained by **Elad Aybes**.
