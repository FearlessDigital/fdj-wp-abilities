# FDJ WordPress Abilities

Starter plugin registering WordPress Abilities (via the [Abilities API](https://github.com/WordPress/abilities-api)) so the [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin's default server can expose them to an AI client (Claude).

Built by Fearless Digital Journey to prototype AI-assisted site building/editing, with an eye toward Avada/Fusion Builder page work.

## Requirements

- WordPress 6.9+ (ships the Abilities API in core)
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, active
- A WordPress user with an Application Password (Users → Profile → Application Passwords), used by whatever client connects (e.g. the `@automattic/mcp-wordpress-remote` proxy from Claude Desktop's local MCP config)

## Abilities registered

| Ability ID | What it does | Destructive? |
|---|---|---|
| `fdj/get-post` | Read a single post/page by ID, raw `post_content` included | No |
| `fdj/update-post-content` | Overwrite a post/page's content (and optionally title/status) | Yes |
| `fdj/list-posts` | Search/list posts or pages by type, status, search term | No |
| `fdj/create-post` | Create a new post/page | No |

All abilities check WordPress capabilities (`edit_post`, `edit_posts`, `publish_posts`, etc.) via `permission_callback`, so access is scoped to whatever WP user/application password authenticates the MCP connection — same as normal WP permissions. No bypass of core capability checks.

## Install

1. Zip this folder (or clone directly) into `wp-content/plugins/fdj-wp-abilities/`
2. Activate in WP Admin → Plugins
3. Confirm MCP Adapter is also active
4. Abilities become discoverable at `https://<site>/wp-json/mcp/mcp-adapter-default-server`

## Roadmap / next abilities to add

- `fdj/list-fusion-builder-elements` — parse an Avada page's `post_content` and return the Fusion Builder shortcode tree as structured data, so edits can target a specific element instead of overwriting the whole page
- `fdj/update-post-meta` — read/write arbitrary post meta (Avada often stores layout options in meta, not just post_content)
- `fdj/get-theme-options` — read Avada's global theme option settings
- `fdj/upload-media` — upload an image/file to the media library and return its attachment ID + URL, for use in Fusion Builder image elements

## Version history

- `0.1.0` — initial four abilities (get/update/list/create posts)
