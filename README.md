# FDJ WordPress Abilities for MCP

Self-contained plugin that connects any WordPress site to Claude over the Model Context Protocol. Registers WordPress Abilities (via the [Abilities API](https://github.com/WordPress/abilities-api)) so the [MCP Adapter](https://github.com/WordPress/mcp-adapter)'s default server can expose them, and bundles everything else needed to get a site connected without SFTP.

Built by Fearless Digital Journey for AI-assisted site building and editing, with an eye toward Avada/Fusion Builder page work.

## What's in the box

| Piece | Why |
|---|---|
| Eight page/post abilities | Read, targeted edit, and rollback |
| Basic auth compatibility shim | Rescues hosts that do not populate PHP_AUTH_USER, no-ops elsewhere |
| One-click connection setup | Generates an Application Password and hands back a paste-ready connect command |
| Health panel | Every failure mode we hit in the field, as a pass/fail row |
| Audit log | Records what ran, as whom, and when |
| Claude Desktop extension | Per-site `.mcpb`, installs on double-click, no config editing |

## Requirements

- WordPress 6.9+ (the Abilities API ships in core from 6.9)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, active. On Pressable, enable under **Tools > WordPress MCP**
- HTTPS, so Application Passwords are available

## Install

1. Upload the zip under **Plugins > Add New > Upload Plugin**
2. Activate
3. Go to **Tools > Claude MCP**
4. Check the Health panel is all green
5. Pick a user, click **Generate Application Password**, copy the command it gives you
6. Paste that command into your terminal

No SFTP, no file editing, no config files.

## Abilities

| Ability ID | What it does | Type | Default |
|---|---|---|---|
| `fdj/list-posts` | Search/list posts or pages by type, status, search term | Read | On |
| `fdj/search-content` | Find which posts contain a literal string, site-wide, with occurrence counts | Read | On |
| `fdj/get-post` | Read a post by ID. Pass `search` to get only matching regions instead of the whole body | Read | On |
| `fdj/list-revisions` | List stored revisions, the undo history for any edit | Read | On |
| `fdj/replace-in-post` | Targeted find and replace inside one post, with `dry_run` and `expect_count` | Write | Off |
| `fdj/update-post-content` | Overwrite a post's content, and optionally title/status | Write | Off |
| `fdj/create-post` | Create a new post/page | Write | Off |
| `fdj/restore-revision` | Roll a post back to a stored revision | Write | Off |
| `fdj/list-media` | Search/list media library attachments by title, caption, MIME type | Read | Off |
| `fdj/get-media` | One attachment by ID, with every registered image size and its own URL/dimensions | Read | Off |
| `fdj/update-media` | Caption, alt text, title, description on up to 200 attachments per call; galleries and lightboxes read captions from here, not the page | Write | Off |
| `fdj/get-post-meta` | Every custom field on one post/page/product — the WooCommerce native abilities have no custom-fields escape hatch | Read | Off |
| `fdj/update-post-meta` | Write or delete custom fields on one post — builders keep per-page layout here, not in `post_content` | Write | Off |
| `fdj/set-post-terms` | Assign taxonomy terms, including the private taxonomies builders use to type their reusable parts | Write | Off |

### Site and theme configuration

Enough to stand a site up from nothing, rather than only edit one that already exists.

| Ability ID | What it does | Type | Default |
|---|---|---|---|
| `fdj/get-theme-info` | Active theme, registered nav menu locations and sidebars, detected builder | Read | Off |
| `fdj/list-options` | Find option names matching a term, within the safe-prefix allowlist | Read | Off |
| `fdj/get-option` | Read one option by exact name, same allowlist | Read | Off |
| `fdj/replace-in-option` | Find and replace a literal string in one option field | Write | Off |
| `fdj/set-option-value` | Set a value at a path inside an option, **creating missing keys** | Write | Off |
| `fdj/set-core-setting` | One of a fixed list of core settings: static front page, site title, permalinks, timezone | Write | Off |
| `fdj/manage-menu` | Create a nav menu, set its items, assign it to a theme location | Write | Off |
| `fdj/upload-media` | Sideload a publicly reachable URL into the media library | Write | Off |
| `fdj/upload-media-data` | Create an attachment from base64 content, for files not published anywhere | Write | Off |
| `fdj/delete-post` | Trash a post/page; `force` for permanent delete | Write | Off |

Every ability checks WordPress capabilities (`edit_post`, `edit_posts`, `create_posts`) through its `permission_callback`, so access is bounded by whichever user authenticates the connection. There is no bypass of core capability checks. Writes ship disabled so a freshly activated site can read and nothing more until someone decides otherwise.

### Gravity Forms

Only present on sites that run Gravity Forms; `get_definitions()` returns empty otherwise, so nothing appears here or in the settings screen on any other site. All six are read-only, gated on Gravity Forms' own `gravityforms_view_entries` / `gravityforms_edit_forms` capabilities rather than a WordPress core one.

| Ability ID | What it does | Type | Default |
|---|---|---|---|
| `gravityforms/list-forms` | List every form: ID, title, active/trash state, field count | Read | Off |
| `gravityforms/get-form` | Fields, notifications, confirmations, and settings for one form. `sections` narrows the response | Read | Off |
| `gravityforms/list-entries` | Search/paginate a form's entries, field values labeled instead of raw numeric IDs | Read | Off |
| `gravityforms/get-entry` | One entry by ID, same labeling | Read | Off |
| `gravityforms/list-feeds` | Every add-on feed on a form (WooCommerce order feed, payment gateway, etc.), regardless of which add-on owns it | Read | Off |
| `gravityforms/list-addons` | Every active Gravity Forms add-on site-wide | Read | Off |
| `gravityforms/update-entry` | Set field values (e.g. an admin-only Status), add a note, or move an entry between active and spam. Cannot delete or trash. `dry_run` | Write | Off |

`gravityforms/get-form`'s `notifications` section is the direct answer to "who gets emailed and when a form is submitted"; `confirmations` shows whether submission redirects to another page rather than just displaying a message. There is deliberately no resend-notifications ability — that action replays a form's configured notification to whatever recipients are configured *today*, for entries that already ran once, and a mis-scoped bulk resend is exactly the kind of surprise this plugin exists to prevent, not enable.

## How Claude sees this

The MCP Adapter does not expose one tool per ability. It exposes three meta-tools:

```
mcp-adapter-discover-abilities
mcp-adapter-get-ability-info
mcp-adapter-execute-ability
```

Abilities are invoked through the third as `{"ability_name": "fdj/list-posts", "parameters": {...}}`. Claude discovers first, then executes.

## The editing model

Prefer targeted edits over whole-page rewrites. On a page-builder page the full body can be 100KB of shortcodes, so reading it and writing it back costs that twice and risks damaging parts you never intended to touch.

```
search-content   find where the string lives, site-wide
get-post         read only the region that matched, via the search parameter
replace-in-post  dry_run to preview, then patch with expect_count set
list-revisions   undo if it went wrong
```

Three guards worth knowing:

- **`expect_count`** on a replace. State how many matches you expect. If the real count differs, nothing is written and the actual number is reported. This is what stops "update the phone number" from quietly rewriting forty places.
- **`dry_run`** on a replace. Returns the match count and surrounding context without saving.
- **`expected_modified`** on any write. Pass the `modified` value you last read, and the write is refused if a human edited the post in wp-admin in the meantime rather than silently destroying their work.

## Client setup without a terminal

Tools > Claude MCP has a **Download connector** button that builds a Claude Desktop extension (`.mcpb`) for this specific site. The endpoint URL and username are baked in as defaults, so whoever installs it fills exactly one field.

Their whole flow:

1. Open the site's WP admin, Tools > Claude MCP
2. Generate Application Password, copy it
3. Download connector
4. Double-click the file, paste the password, Install

No terminal, no JSON, nothing to install for the bridge, since Claude Desktop ships its own Node runtime.

The bundle contains a manifest and a zero-dependency Node bridge of about 5KB, so the whole extension is roughly 3KB. It was built that way rather than vendoring `@automattic/mcp-wordpress-remote` and its dependency tree: all that is needed is stdio to HTTP with Basic auth, and this way there is nothing to keep patched and the bundle installs offline.

**The password is not baked into the bundle.** It could be, and it would remove a step, but the file would then be a live credential travelling by email. Claude Desktop prompts for it at install and stores it in the OS keychain.

Requires the PHP `ZipArchive` extension. The settings screen says so plainly if a host lacks it, and everything else keeps working.

## Security model

**The plugin never stores an Application Password.** The password is a *client* credential. WordPress is the server and core validates it per request, so a stored copy would be a liability with no benefit. The admin screen generates one, displays it exactly once, and forgets it. Revoke under **Users > Profile > Application Passwords**.

Recommended: connect as a dedicated user with the lowest role that does the job, rather than the site owner's admin account. Revoking access then means deleting one user.

## Two traps this plugin exists to solve

Both cost real hours before being understood, and both are silent.

**1. Application Passwords can fail silently on some hosts.** WordPress core's app-password check reads only `$_SERVER['PHP_AUTH_USER']` and `PHP_AUTH_PW`. Apache with mod_php fills those in automatically; some nginx and PHP-FPM setups do not, even while passing the `Authorization` header through untouched. Where that happens, every Application Password login fails with a bare `rest_not_logged_in`. The shim in the main plugin file decodes the header into the variables core reads, and no-ops where the host already handles it.

How common this is varies by host, so do not assume. The health panel's "Basic auth reaching PHP" row tells you which case a given site is in, and distinguishes the host doing it natively from another mu-plugin doing it.

A warning about diagnosing this by hand: **a valid username with a wrong password also returns `rest_not_logged_in`**, not `incorrect_password`. That test therefore cannot distinguish broken auth plumbing from a simple typo in the credential, and mistaking one for the other will send you a long way down the wrong path. Use the health panel instead.

If a host genuinely strips the `Authorization` header outright, `.htaccess` fixes found online do nothing on nginx, and some managed hosts (Pressable among them) will not edit nginx config even by support request. In that case send the credential as `X-Authorization` instead, which this plugin also accepts.

**2. `meta.public` does not exist in WP 6.9/7.0.** Abilities register successfully but stay invisible to REST and MCP unless meta sets `show_in_rest => true` and `mcp => ['public' => true]` explicitly. The `meta.public` shorthand that seeds both landed in core after 7.0, so trunk source and much of the documentation are misleading here. Symptom: the ability appears in `wp_get_abilities()` but is absent from `/wp-json/wp-abilities/v1/abilities`. This plugin always sets the specific keys, which is correct on every version.

## Updates (Git Updater)

Updates are delivered from this GitHub repo via [Git Updater](https://git-updater.com/). Once set up, new versions appear on each site's Plugins screen like any other update.

**One-time, per client site:** install and activate Git Updater. If the repo is private, add a GitHub personal access token under Settings > Git Updater.

**To ship a release:**

```bash
./bin/release.sh          # preflight: lint, version consistency, headers, tag availability
git add -A
git commit -m "Release X.Y.Z"
git tag X.Y.Z
git push origin main --tags
```

Then create a GitHub Release for that tag.

Three things that silently break a release, all checked by `bin/release.sh`:

- **The `Version:` header must exactly match the git tag.** A mismatch means no update is offered, with no error anywhere to tell you why.
- **`Primary Branch: main` must be present.** Git Updater defaults to `master`, and its absence produces a 404 on every update check.
- **Bump the version in all three places**: the `Version:` header, the `FDJ_MCP_VERSION` constant, and `Stable tag` in `readme.txt`.

`.gitattributes` marks dev files `export-ignore`, so the archive GitHub generates for a tag contains only what belongs in `wp-content/plugins`. Never deploy by copying the working folder: a `.git` directory inside a plugin folder cannot be removed by WordPress, because git object files are mode 444, so the updater aborts partway through and leaves a broken stump.

## Roadmap

### Admin UI

Built and shipping:

- **Select all**, plus a per-group "select all / none" toggle next to each group heading. Pure client-side JS against the existing checkboxes; the save handler is unchanged.
- **Theme/plugin detection.** Any ability definition can carry a `requires` tag (`avada`, `gravityforms`, more later). `fdj_mcp_integration_detected()` in the main plugin file is the one place that knows how to check each tag; the settings screen, `register()`, and the health panel all filter through it. A group with nothing behind it does not render at all, rather than showing a checkbox that could never work. A saved toggle for a currently-hidden ability is preserved rather than wiped, so switching a theme back on restores it automatically. Adding the next integration (Elementor, Divi) means tagging its abilities and adding one case to that function, not touching the settings screen.

Still open:

- **"N new abilities added in this version" notice.** Saved toggles persist across updates, so a release that adds an ability leaves it off on existing installs with nothing on screen saying so. sophere.org sat on four of eight abilities for several releases before anyone noticed.

### Fusion Builder abilities (Avada)

Built and shipping:

- `fdj/list-fusion-builder-elements` — read. Parses a page's `post_content` into a flat list of elements: type, position, a text preview, and which style attributes are literal overrides versus inherited from the site's global theme (`var(--awb-...)` tokens).
- `fdj/update-fusion-element` — write. Sets attributes on one located element, or removes it. Refuses to remove tags that can nest inside themselves (container/row/column).
- `fdj/upload-media` — write. Sideloads a URL into the media library.
- `fdj/delete-post` — write. Trashes a post/page; `force` for permanent delete.

- `fdj/update-post-meta` — write. Shipped in 1.4.0. The case that proved it necessary: a from-scratch Avada build, where a Layout Section is only recognised as a header because of its `fusion_tb_category` term and a page's background colour lives in `pyre_*` meta, so a page can look wrong while every shortcode in it is already correct.
- `fdj/set-post-terms` — write. Shipped in 1.4.0, for that same taxonomy.
- `fdj/avada-reset-caches` — write. Shipped in 1.4.0. Avada compiles Theme Options and shortcode style attributes into cached CSS server-side, and a write made through these abilities does not trigger the regeneration that saving in wp-admin does.

Phase 2, for site-wide changes ("make every H1 74px") rather than one page at a time:

- Reading and writing Avada's global Theme Options is covered as of 1.4.0, but by the generic `fdj/get-option` / `fdj/set-option-value` pair against `fusion_options` rather than by Avada-specific abilities. Avada's option names are stable and self-describing, and a generic path-addressed writer does not need updating every time Avada adds a field, so the specific `get-theme-options`/`update-theme-options` pair is no longer planned.
- Still open: a site-wide version of `list-fusion-builder-elements`, to find every element with a literal override on a given property before a global change silently gets masked by them.

### Other builders

Elementor and Divi, once Fusion Builder is solid and this ships. Divi next. The `requires` tag and detector this version added is the piece that was missing to do this without a settings-screen change each time.

### User abilities

Built in 1.8.0, core WordPress, no dependency:

- `fdj/list-users` — search by name, email, login or role. Run it before creating anyone: a returning member already having an account is the usual case and the usual cause of a duplicate.
- `fdj/create-user` — takes no password and never returns one. A long random password is generated internally, and with `notify` on WordPress sends the person its own set-a-password link. Roles are limited to subscriber, contributor, author. Idempotent by email: an existing account comes back as `existing: true` rather than erroring or duplicating, so a half-finished import can simply be run again. `dry_run` shows the username that would be taken.
- `fdj/send-password-reset` — the standard "Lost your password?" email for one existing account, for someone whose account predates the process or who never acted on the original.
- `fdj/set-post-author` — reassign a post, page or listing to its real owner. Nothing else in the plugin could change `post_author`, and on a directory site a listing owned by the wrong account shows the owner an empty dashboard.
- `fdj/create-post` also gained an optional `author`, so a listing can be created under its owner in one call instead of two. An author that cannot be resolved is refused rather than quietly falling back to the connection's own account, which would look like success and leave the real owner locked out.

Deliberately not built: deleting users, changing an existing user's role or email address, and setting a password directly. Each one turns account creation into account takeover, and none of them is needed to stand up a listing.

### GeoDirectory abilities

Built in 1.9.0, active only on sites running GeoDirectory:

- `fdj/get-listing` — the fields that are not post meta: address, coordinates, contact details, package, and every custom field. Without it a listing reads as a title and a description while its visible address is unreachable.
- `fdj/update-listing` — the only way to write those fields. Validates names against the listing table's own columns, so a custom field added in GeoDirectory's settings works immediately and a typo is refused rather than accepted and ignored. **Reads every value back and reports what is actually stored**, because GeoDirectory rebuilds a listing's row at the end of the request that created the post: fields written in that same request are replaced by defaults, and the plugin re-geocodes from what survives. On a real batch that produced a Sarasota teacher with a Chico, California address, on a page that otherwise looked right. Create the post in one call, set its fields in the next.
- `fdj/list-listing-fields` — what fields exist and what a select field's options are. Option lists are omitted from the full listing on purpose: one field on a live site holds 435 options.
- `fdj/add-listing-field-option` — add options to a select or multiselect without reordering or dropping what is there. The storage is newline-separated while a listing's values are comma-separated, and a value that is not an option fails silently.
- `fdj/geocode-address` — city, region, postcode, country and coordinates from an address, using the site's own Maps key so the key stays server-side. Real address lists are too inconsistent to parse by hand, and a listing with no coordinates never appears on the map.

Deliberately not built: creating or deleting a listing. Creation is `fdj/create-post` followed by `fdj/update-listing`, which is also the sequence that avoids the overwrite above.

### Gravity Forms abilities

Built and shipping, active only on sites running Gravity Forms (nothing appears here, in the settings screen, or anywhere else on a site that doesn't):

- `gravityforms/list-forms`, `get-form`, `list-entries`, `get-entry`, `list-feeds`, `list-addons` — all read. `gravityforms/update-entry` (1.7.0) is the one write: it records what an agent did with a submission (field values such as a Status, a note, active vs spam) and cannot delete, trash, resend or submit. `get-form`'s `notifications` section is the direct answer to "who gets emailed and on what event"; its `confirmations` section shows whether a submission redirects to another page instead of just displaying a message. `list-feeds` surfaces every add-on feed on a form (a WooCommerce order feed, a payment gateway, anything else) by `addon_slug`, regardless of which specific add-on created it. Entry field values are labeled from the form's own field labels rather than left as raw numeric IDs.

Deliberately not built:

- **Resend notifications.** Replays a form's *currently configured* notification to matching entries at once, including old ones — a plausible explanation for one real incident already (a club treasurer suddenly receiving copies of months of old order notifications, PWDCNC, Aug 2026). If this is ever needed, it should be its own narrowly-scoped write ability: `dry_run` first, an explicit list of entry IDs rather than "all matching", and a hard cap. Not a shortcut bolted onto a read ability.
- **Editing notifications/confirmations/feeds.** Same shape as the write abilities the page/post side already has; held back only because nothing has needed it yet.
- **Entry export (CSV).** Gravity Forms already does this natively; low priority unless a workflow specifically needs Claude to generate one on demand.

### Other integrations, watching for a real need rather than building ahead of one

- **Contact Form 7.** Same shape of problem as Gravity Forms, for any client site that runs it instead. No current site is known to need this.
- **Nav menus.** Covered since 1.4.0 by `fdj/manage-menu`.
- **Users.** Asked for, and built in 1.8.0 (see below) once a real task needed it: a teacher directory where every listing has to be owned by its own subscriber account. The blast-radius concern that kept it out until then is answered by narrowing the surface rather than by trusting the caller: a role allowlist that stops at `author`, no password parameter anywhere, no delete, no role change on an existing user, and no way to edit an existing account's email address.
- **Roles and capabilities themselves.** Still out. Creating a user inside a fixed allowlist is a different risk from editing what a role can do, and nothing has needed the latter.

## Version history

- `1.9.0` - GeoDirectory abilities: `fdj/get-listing`, `fdj/update-listing`, `fdj/list-listing-fields`, `fdj/add-listing-field-option`, `fdj/geocode-address`. Listing fields live in GeoDirectory's own table, invisible to every other ability. `update-listing` verifies its own writes because GeoDirectory can undo them without an error.
- `1.8.0` - user abilities: `fdj/create-user`, `fdj/list-users`, `fdj/send-password-reset`, `fdj/set-post-author`, and an optional `author` on `fdj/create-post`. A build could make the listing but not the person who owns it, so every directory import stopped at wp-admin. No password is ever taken or returned; WordPress's own new-user email carries the set-password link.
- `1.4.2` - fixed `fdj/update-post-meta` and `fdj/set-post-terms` being enabled but never registered: both were given a `content` category in 1.4.0, and nothing registers one, so the Abilities API refused them while the settings screen still showed them ticked. The health panel's exposed-count check is what surfaced it.
- `1.7.0` - `gravityforms/update-entry`: set field values (e.g. an admin-only Status), add a note, or move an entry between active and spam, with `dry_run`. The first Gravity Forms write; before it an agent could read a queue of submissions but never mark one done.
- `1.6.0` - `fdj/update-media`: bulk caption/alt/title/description writes on attachments, with `dry_run`. Captions live in `post_excerpt` and alt text in `_wp_attachment_image_alt`, neither reachable by any other ability, and Avada galleries read captions from the attachment, so a correctly built gallery still showed none.
- `1.4.1` - two fixes found by using 1.4.0 on a real build: `fdj/avada-reset-caches` fatalled on Avada 7.16 because `Fusion_Dynamic_CSS::reset_all_caches()` is an instance method and `method_exists()` does not distinguish, aborting the ability *after* the first reset had run; and `fdj/set-core-setting` saved `permalink_structure` without effect, because `$wp_rewrite` was already built from the old structure.
- `1.4.0` — eight abilities for building a site from nothing rather than editing one that exists: `fdj/set-option-value` (the counterpart to `replace-in-option`, which can only rewrite a string already present — on a fresh site Avada's `fusion_options` holds one key, so nearly every global setting has to be created), `fdj/set-core-setting` (fixed allowlist; setting a static front page had no other route), `fdj/update-post-meta`, `fdj/set-post-terms`, `fdj/manage-menu` (menus are terms with ordered posts hanging off them, so nothing else here could reach them), `fdj/upload-media-data` (base64, for photography that has never been published to a URL), `fdj/get-theme-info` (menu location slugs and sidebar IDs are theme-specific; guessing one wastes a write), and `fdj/avada-reset-caches`.
- `1.3.2` — fdj/get-post-meta, for reading custom/plugin fields on a post, page, or WooCommerce product. woocommerce/products-query and product-update are both hard-capped to a fixed catalog field set (`additionalProperties: false`) with no way to see something like a product-to-form link a plugin stored as post meta.
- `1.3.1` — fixed gravityforms/* abilities returning Permission denied for an administrator on any site where Gravity Forms' own granular capabilities were never seeded onto a role (common on older installs); both permission checks now fall back to manage_options
- `1.3.0` — Gravity Forms abilities (list-forms, get-form, list-entries, get-entry, list-feeds, list-addons), read-only, active only when Gravity Forms is present. Settings screen groups abilities by the theme/plugin they require, hides a group entirely when it is not active, and preserves a hidden group's saved toggles rather than wiping them. Added select-all and per-group select-all/none. Added `fdj/list-media` and `fdj/get-media`, closing the read-side gap next to the existing write-only `fdj/upload-media`.
- `1.0.1` — health panel no longer reports "working natively" when another mu-plugin is actually supplying `PHP_AUTH_USER`; it now detects duplicates and says so
- `1.0.0` — self-contained release: auth shim, admin setup screen with credential generation, health panel, audit log, per-ability toggles, writes off by default
- `0.2.0` — fixed ability visibility (`show_in_rest` + `mcp.public` set explicitly)
- `0.1.0` — initial four abilities (get/update/list/create posts)
