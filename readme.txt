=== FDJ WordPress Abilities for MCP ===
Contributors: fearlessdigital
Tags: mcp, ai, claude, abilities, rest-api
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect a WordPress site to Claude over the Model Context Protocol. Upload, activate, generate a credential, done.

== Description ==

Registers WordPress Abilities so the MCP Adapter's default server can expose page and post operations to an AI client, and bundles everything else a site needs to actually connect:

* Eight page/post abilities (list, search, read, revisions, replace, update, create, restore), each bounded by normal WordPress capability checks
* A Basic auth compatibility shim for hosts that do not populate PHP_AUTH_USER
* One-click setup that generates an Application Password and returns a paste-ready connection command
* A health panel covering every known failure mode in the connection chain
* An audit log of every ability invocation
* A downloadable Claude Desktop extension (.mcpb) for one-click setup

The plugin never stores an Application Password. It generates one, shows it once, and forgets it.

Write abilities ship disabled. A freshly activated site can read and nothing else until someone decides otherwise.

== Installation ==

1. Ensure the MCP Adapter plugin is active. On Pressable, enable it under Tools > WordPress MCP.
2. Upload the zip under Plugins > Add New > Upload Plugin, then activate.
3. Go to Tools > Claude MCP and confirm the health panel is green.
4. Enable the abilities that site should expose.
5. Click Generate Application Password and paste the resulting command into your terminal.

== Frequently Asked Questions ==

= Does this store my Application Password? =

No. The password is a client credential. WordPress validates it per request through core, so a stored copy would be a liability with no benefit. It is displayed exactly once and never written to the database.

= Why can Claude not see my abilities? =

On WordPress 6.9 and 7.0 an ability must set `meta.show_in_rest` and `meta.mcp.public` explicitly. The `meta.public` shorthand does not exist in those versions, so abilities using it register successfully but stay invisible. This plugin always sets the specific keys.

= Application Password logins return rest_not_logged_in. =

Check the Basic auth row in the health panel. Some hosts pass the Authorization header but never populate PHP_AUTH_USER, which is the only thing core reads. The bundled shim handles that case.

== Changelog ==

= 1.2.0 =
* New: Download connector (.mcpb). Generates a Claude Desktop extension for this site, with the endpoint URL and username already filled in, so installing is double-click plus paste one password. No JSON config editing.
* The bundle ships a zero-dependency Node bridge (about 5KB) rather than vendoring an npm package, so the extension is around 3KB total, installs offline, and has no dependency tree to keep patched.
* The Application Password is deliberately not baked into the bundle. Claude Desktop prompts for it at install and stores it in the OS keychain.

= 1.1.0 =
* New: fdj/replace-in-post. Targeted find and replace inside one post, with dry_run to preview and expect_count as a safety guard. Far cheaper and far safer than rewriting whole content.
* New: fdj/search-content. Literal string search across the whole site with per-post occurrence counts.
* New: fdj/list-revisions and fdj/restore-revision. Undo for any edit made through these abilities.
* fdj/get-post gains a "search" parameter that returns only matching regions with context, instead of the entire body.
* All writes accept expected_modified, a concurrency guard that refuses the write if the post changed since it was read.
* fdj/create-post now returns edit_url. Write abilities now return modified.
* Note for existing installs: new abilities are off until enabled under Tools > Claude MCP, since your saved toggles are preserved on update.

= 1.0.2 =
* Docs: corrected an overstated claim that Application Password auth "usually" fails on nginx/PHP-FPM hosts. Many populate PHP_AUTH_USER natively; the health panel reports which case a site is in.
* Docs: added a warning that a valid username with a wrong password also returns rest_not_logged_in, so that symptom cannot be used to diagnose auth plumbing.

= 1.0.1 =
* Health panel no longer reports "working natively" when another mu-plugin is supplying PHP_AUTH_USER. It now detects duplicates and names them.
* Added Git Updater support.

= 1.0.0 =
* Self-contained release: auth shim, admin setup screen with credential generation, health panel, audit log, per-ability toggles.
* Write abilities now default to off.

= 0.2.0 =
* Fixed ability visibility by setting show_in_rest and mcp.public explicitly.

= 0.1.0 =
* Initial four abilities.
