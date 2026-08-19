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

Every ability checks WordPress capabilities (`edit_post`, `edit_posts`, `create_posts`) through its `permission_callback`, so access is bounded by whichever user authenticates the connection. There is no bypass of core capability checks. Writes ship disabled so a freshly activated site can read and nothing more until someone decides otherwise.

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

- **Toggle all / toggle none for the ability list.** Ticking eight boxes by hand is tedious on one site and worse across many. This is not only convenience: because saved toggles are preserved across updates, every release that adds an ability leaves it switched off on existing installs, and the omission is silent. sophere.org sat on four of eight abilities for several releases without anything on screen saying so. A bulk control plus a "3 new abilities added in this version" notice would close both gaps.

### Next abilities to add

- `fdj/list-fusion-builder-elements` — parse an Avada page's `post_content` and return the Fusion Builder shortcode tree as structured data, so edits can target a specific element instead of overwriting the whole page
- `fdj/update-post-meta` — read/write arbitrary post meta (Avada often stores layout options in meta, not just post_content)
- `fdj/get-theme-options` — read Avada's global theme option settings
- `fdj/upload-media` — upload an image/file to the media library and return its attachment ID + URL, for use in Fusion Builder image elements

## Version history

- `1.0.1` — health panel no longer reports "working natively" when another mu-plugin is actually supplying `PHP_AUTH_USER`; it now detects duplicates and says so
- `1.0.0` — self-contained release: auth shim, admin setup screen with credential generation, health panel, audit log, per-ability toggles, writes off by default
- `0.2.0` — fixed ability visibility (`show_in_rest` + `mcp.public` set explicitly)
- `0.1.0` — initial four abilities (get/update/list/create posts)
