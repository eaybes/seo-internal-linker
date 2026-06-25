# SEO Internal Linker

A WordPress plugin that automatically turns the **first occurrence** of a defined phrase in a post into a link to a target page — a lightweight, self-hosted way to build glossary-style internal links (e.g. automatically linking "climate crisis" the first time it appears in an article to your climate crisis explainer page).

**Created by [Elad Aybes](https://github.com/eaybes).**

---

## Table of Contents

- [Features](#features)
- [How It Works](#how-it-works)
- [Installation](#installation)
- [Usage](#usage)
  - [Managing Phrases](#managing-phrases)
  - [Settings](#settings)
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

- **Automatic first-occurrence linking** — define a phrase and a target URL once; the plugin links the first plain-text appearance of that phrase in each post.
- **Runs on posts by default** — enabled automatically for the `post` post type.
- **Opt-in for pages** — disabled by default on `page`, with a per-page checkbox to enable it.
- **Per-post opt-out** — disable automatic linking entirely on an individual post via a checkbox in the editor sidebar.
- **Per-phrase override per post** — uncheck any phrase in the post editor's meta box to stop it from being auto-linked on that specific post, without affecting other posts.
- **Manual-unlink detection** — if an editor manually deletes an auto-inserted link from the content, the plugin remembers that and won't re-insert it on the next save.
- **Skips headings and existing links** — a phrase that already appears inside an `<h1>`–`<h6>` or any existing `<a>` tag counts as "already used" and won't get a second, possibly conflicting, link elsewhere.
- **RTL / Hebrew-safe** — all string matching and DOM manipulation uses multibyte-safe functions and correctly round-trips UTF-8/RTL content.
- **Background-batched rescanning** — re-apply the current phrase list to all existing content via WP-Cron in small batches, so it won't time out on large sites.
- **Live usage statistics** — each phrase tracks how many posts currently contain an auto-generated link to it, kept in sync as content is created, edited, or deleted.
- **Configurable link behavior** — optionally open auto-inserted links in a new tab (`target="_blank" rel="noopener noreferrer"`).
- **Hardened against malicious input** — target URLs are restricted to `http`/`https` at multiple layers (see [Security](#security)).

## How It Works

1. You define a list of **phrase → target URL** pairs in the plugin's admin screen.
2. Whenever a `post` (or an enabled `page`) is saved, the plugin:
   - Strips any links it previously inserted (so target URL or phrase-list changes are picked up).
   - Parses the content into a DOM tree.
   - For each phrase (longest first, to avoid partial/overlapping matches), finds the **first** text node containing that phrase.
   - If that occurrence is already inside a link or a heading, the phrase is treated as "used" and skipped — no link is added.
   - Otherwise, it wraps just that one occurrence in `<a href="..." class="sil-link" data-sil-id="...">`.
3. The rewritten content is saved back to `post_content`. The conversion is **permanent** (stored in the database), not a render-time filter — so the links are visible in the editor itself.

## Installation

1. Download the plugin ZIP (or clone this repository) into your `wp-content/plugins/` directory.
2. In **WordPress Admin → Plugins**, find **SEO Internal Linker** and click **Activate**.
   - On activation, the plugin creates its own database table (`wp_sil_phrases`) to store phrases and target URLs.
3. A new **Internal Linker** menu item appears in the WordPress admin sidebar.

### Installing on additional sites

This plugin has no external dependencies — copy the plugin folder (or upload the ZIP via **Plugins → Add New → Upload Plugin**) to any other WordPress install and activate it the same way. Phrases are stored per-site in the database, so you'll need to re-enter them (or migrate the `wp_sil_phrases` table) on each new site.

## Usage

### Managing Phrases

Go to **WordPress Admin → Internal Linker**:

- **Add new phrase**: enter the exact phrase text and a target URL (must be `http://` or `https://`).
- **Existing phrases** table shows each phrase, its target, and how many posts currently link to it (**Active links** column).
- **Edit** / **Delete** are available per row.

### Settings

On the same screen, under **Settings**:

- **Open auto-inserted links in a new tab** — when enabled, all auto-generated links get `target="_blank" rel="noopener noreferrer"`.

### Per-Post / Per-Page Controls

A **SEO Internal Linker** meta box appears in the editor sidebar:

- **On pages**: a checkbox to *enable* automatic linking (off by default).
- **On posts**: a checkbox to *disable* automatic linking (on by default).
- **On both**: a checklist of all defined phrases — uncheck any phrase to stop it from being auto-linked on this specific piece of content. If you manually delete an auto-inserted link from the content, the corresponding phrase is automatically added to this list so it won't reappear.

### Rescanning Existing Content

After adding, editing, or deleting phrases, existing posts won't update until you rescan:

1. Go to **Internal Linker** and click **Rescan all content now**.
2. The plugin queues all eligible posts/pages and processes them in batches of 20 via WP-Cron, so it won't time out on large sites.
3. Progress (`X of Y processed`) is shown on the admin page — refresh to check status.

## Matching Rules

- Matching is **exact and case-sensitive** — the phrase must appear verbatim.
- Only the **first** occurrence per post is linked; subsequent occurrences of the same phrase are left untouched.
- A phrase that already appears inside an existing `<a>` tag (any link, not just ones this plugin created) or inside a heading (`<h1>`–`<h6>`) counts as the "first occurrence" — it won't be linked a second time elsewhere in the post.
- Phrases are processed **longest-first**, so a longer phrase that contains a shorter one (e.g. "climate crisis" vs. "climate") is matched before the shorter one can claim part of it.
- Content inside `<script>` and `<style>` tags is never touched.

## Security

This plugin was built with the following safeguards:

- **URL scheme allowlisting**: target URLs are validated to `http`/`https` only at the point of input (`esc_url_raw()` with an explicit protocol allowlist), rejecting `javascript:`, `data:`, and similar XSS vectors.
- **Defense in depth at render time**: even if a row somehow contains an unsafe URL, the link-rendering code re-validates the URL with `esc_url()` and silently skips inserting the link rather than writing unsafe markup into post content.
- **Capability checks**: all administrative actions (adding/editing/deleting phrases, changing settings, triggering a rescan) require the `manage_options` capability. Per-post/per-page toggles require `edit_post`.
- **CSRF protection**: every state-changing admin action is protected with a WordPress nonce (`check_admin_referer()` / `wp_verify_nonce()`).
- **Output escaping**: all admin-screen output uses `esc_html()`, `esc_attr()`, `esc_url()`, and `esc_js()` as appropriate.
- **Input sanitization**: phrase text has tags stripped and whitespace normalized; length is capped to match the database column.
- **XXE protection**: the DOM parser used to rewrite post content is loaded with `LIBXML_NONET` and explicit entity-loader hardening, and never resolves external entities.
- **Safe redirects**: all post-action redirects use `wp_safe_redirect()`.
- **No remote calls**: the plugin does not send any data off-site; everything runs locally against your own WordPress database.

If you discover a security issue, please open an issue or contact the maintainer directly rather than disclosing it publicly.

## Database Schema

The plugin creates a single table, `{$wpdb->prefix}sil_phrases`:

| Column        | Type                  | Notes                          |
|---------------|------------------------|---------------------------------|
| `id`          | `BIGINT UNSIGNED`      | Primary key, auto-increment     |
| `phrase`      | `VARCHAR(255)`         | Unique                          |
| `target_url`  | `VARCHAR(2048)`        | Validated to `http`/`https`     |
| `usage_count` | `BIGINT UNSIGNED`      | Number of posts currently linking to this phrase |
| `created_at`  | `DATETIME`             |                                  |

Schema upgrades are versioned and applied automatically on `plugins_loaded` — no manual deactivate/reactivate required.

Per-post/per-page data is stored as standard WordPress post meta:

| Meta key                | Used on      | Purpose                                          |
|--------------------------|-------------|---------------------------------------------------|
| `_sil_disabled`          | posts        | `'1'` disables auto-linking on this post          |
| `_sil_enabled`           | pages        | `'1'` enables auto-linking on this page           |
| `_sil_skip_phrases`      | posts/pages  | Array of phrase IDs excluded on this content      |
| `_sil_active_phrases`    | posts/pages  | Array of phrase IDs currently auto-linked here    |

## Hooks Reference

| Hook                          | Type   | Fired by              | Purpose                                  |
|-------------------------------|--------|------------------------|-------------------------------------------|
| `save_post`                   | action | WordPress core         | Triggers relinking on eligible content    |
| `before_delete_post`          | action | WordPress core         | Releases usage counts when a post is deleted |
| `sil_process_rescan_batch`    | action | WP-Cron (this plugin)  | Processes the next batch of the rescan queue |

## FAQ

**Does this change my content permanently, or just at render time?**
Permanently — the generated `<a>` tags are written into `post_content` and stored in the database. You'll see them in the block/classic editor.

**What happens if I change a phrase's target URL?**
Existing posts keep the old link until you click **Rescan all content now**, which strips and re-applies all auto-links using the current phrase list.

**What if I don't want a specific link in one specific post?**
Open that post, find the **SEO Internal Linker** meta box in the sidebar, and uncheck the phrase. Alternatively, just delete the `<a>` tag manually in the content — the plugin detects this and won't re-add it.

**Does it work with Hebrew/RTL content?**
Yes — all matching and DOM rewriting is multibyte-safe and was specifically tested with Hebrew text.

**Will it slow down my site?**
Linking happens once, on save, not on every page view — there's no runtime overhead for visitors.

## Changelog

### 1.0.0
- Initial release: phrase management, automatic first-occurrence linking, per-post/per-page controls, batched rescanning, usage statistics, and security hardening.

## License

GPL v2 or later, consistent with WordPress plugin licensing conventions.

---

Maintained by **Elad Aybes**.
