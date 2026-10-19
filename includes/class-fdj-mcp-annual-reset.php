<?php
/**
 * Annual-reset abilities.
 *
 * Built for the job a client site repeats every year: set seasonal products
 * back to a known state, archive and clear last year's form entries, check
 * that menus, pages and notification recipients carry this year's information,
 * and find out what is still wrong. Every ability here exists because a real
 * reset needed a step that could only be done by hand in wp-admin or with raw
 * WP-CLI, where there is no dry run, no read-back and no audit log.
 *
 * Three groups, each gated on what the site actually runs:
 *
 *  - Core (any site): fdj/get-menu, fdj/search-everywhere, fdj/annual-reset-report.
 *  - Gravity Forms:   export-entries, trash-entries, update-form, restore-form.
 *  - WooCommerce:     fdj/audit-products, fdj/set-product-fields, fdj/get-order-notes,
 *                     fdj/list-order-items.
 *
 * Design rules carried over from the rest of this plugin:
 *
 *  - Every write has dry_run, and reads its own result back from storage and
 *    reports what is actually stored rather than what it asked for.
 *  - Nothing deletes anything permanently. Entries go to the Gravity Forms
 *    trash, products can only move between publish, draft, pending and private,
 *    and a form is backed up before every change so it can be restored.
 *  - trash-entries refuses to run unless the same entries were exported first
 *    with export-entries, and refuses entries from the current calendar year
 *    unless told otherwise. The thing it protects against is clearing this
 *    year's real submissions while clearing last year's.
 *  - Order abilities never return phone numbers or street addresses. Buyer
 *    names come back only from fdj/list-order-items, which exists because a
 *    show catalog credits sponsors by name; email is opt-in there.
 *
 * Writes ship disabled, like every other write in this plugin.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the annual-reset abilities.
 */
class FDJ_MCP_Annual_Reset {

	/** Option holding form backups taken before update-form / restore-form writes. */
	const BACKUP_OPTION = 'fdj_gf_form_backups';

	/** How many form backups to keep, across all forms. */
	const BACKUP_LIMIT = 25;

	/** Entries per export or trash call. Larger sets must be narrowed with a date range. */
	const ENTRY_LIMIT = 5000;

	/** Hook that deletes an export file after a day. */
	const CLEANUP_HOOK = 'fdj_mcp_delete_export';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'delete_export_file' ) );
	}

	/**
	 * Ability definitions. Groups that need a plugin the site does not run are omitted.
	 *
	 * @return array<string, array>
	 */
	public static function get_definitions() {
		$defs = self::core_definitions();

		if ( class_exists( 'GFAPI' ) ) {
			$defs = array_merge( $defs, self::gravityforms_definitions() );
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$defs = array_merge( $defs, self::woocommerce_definitions() );
		}

		return $defs;
	}

	/**
	 * Register every enabled ability.
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::get_definitions() as $name => $def ) {

			if ( ! fdj_mcp_is_ability_enabled( $name ) ) {
				continue;
			}

			if ( ! fdj_mcp_integration_detected( isset( $def['requires'] ) ? $def['requires'] : '' ) ) {
				continue;
			}

			wp_register_ability(
				$name,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => $def['category'],
					'input_schema'        => $def['input_schema'],
					'output_schema'       => $def['output_schema'],
					'execute_callback'    => $def['execute_callback'],
					'permission_callback' => $def['permission_callback'],
					// Same explicit show_in_rest + meta.mcp.public pairing as the other
					// providers: meta.public alone is invisible to MCP on WP 6.9/7.0.
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => $def['annotations'],
						'mcp'          => array(
							'public' => true,
							'type'   => 'tool',
						),
					),
				)
			);
		}
	}

	/* -----------------------------------------------------------------
	 * Definitions
	 * ----------------------------------------------------------------- */

	/**
	 * Core abilities: any WordPress site.
	 *
	 * @return array<string, array>
	 */
	private static function core_definitions() {
		return array(

			'fdj/get-menu' => array(
				'is_write'            => false,
				'requires'            => '',
				'label'               => 'Get Navigation Menu',
				'description'         => 'Read a navigation menu: every item with its ID, parent, position, type, link target, URL, title and CSS classes, plus the status of the page or product each item points at (a link to a draft or trashed page is a dead link on the live site). Call with no arguments to list every menu and where it is assigned. This is the read half of fdj/manage-menu, which replaces a menu wholesale and so should never be called without reading the menu first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu'     => array(
							'type'        => array( 'integer', 'string' ),
							'description' => 'Menu ID, slug or name. Omit to list all menus.',
						),
						'location' => array(
							'type'        => 'string',
							'description' => 'Theme menu location slug, as an alternative to "menu". See fdj/get-theme-info for the slugs.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'menus' => array( 'type' => 'array' ),
						'menu'  => array( 'type' => 'object' ),
						'items' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_menu' ),
				'permission_callback' => array( __CLASS__, 'can_edit_theme' ),
			),

			'fdj/search-everywhere' => array(
				'is_write'            => false,
				'requires'            => '',
				'label'               => 'Search Everywhere',
				'description'         => 'Find a literal string in every place a site keeps text, and say which place: post titles, post bodies, excerpts (a WooCommerce product\'s short description lives in the excerpt), navigation menu items and their URLs, classic widgets, and (when Gravity Forms is active) form titles, descriptions, field labels, choices, notifications and confirmations. fdj/search-content only searches post bodies, so an old Premium List link or last year\'s date inside a menu, an excerpt or a form is invisible to it. Returns where each hit is, not the surrounding text.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array(
							'type'        => 'string',
							'description' => 'The literal text to find. At least 3 characters.',
						),
						'locations' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => self::search_locations(),
							),
							'description' => 'Restrict to these places. Defaults to all of them.',
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'Max hits per place. Defaults to 50, capped at 200.',
							'default'     => 50,
						),
					),
					'required'   => array( 'search' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'search'  => array( 'type' => 'string' ),
						'results' => array( 'type' => 'object' ),
						'total'   => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_search_everywhere' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			'fdj/annual-reset-report' => array(
				'is_write'            => false,
				'requires'            => '',
				'label'               => 'Annual Reset Report',
				'description'         => 'A read-only checklist of what is still wrong for a yearly reset, so nobody has to hunt. Pass whatever applies and each part runs only if its inputs are given. Products: published items in the given categories that are not managed stock, hold the wrong quantity, sit sold out, or lack a deadline sentence. Menu: duplicate entries, bare "#" links, "coming soon" placeholders, probable page twins, and links to drafts. Stale text: last year\'s dates found in pages, products and events. Forms (Gravity Forms): entries left from before this year, trash counts, newest entry date, and who is emailed. Orders (WooCommerce): failed orders since a date and the new-order email recipients. Nothing is changed. Re-run it after each batch of edits; an empty issues list is the finish line.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'year'               => array(
							'type'        => 'integer',
							'description' => 'The event year being prepared. Defaults to the current year.',
						),
						'category_slugs'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'WooCommerce product category slugs to check, e.g. ["regional-specialty"].',
						),
						'expected_stock'     => array(
							'type'        => 'integer',
							'description' => 'Quantity every managed-stock product should hold. Defaults to 1.',
							'default'     => 1,
						),
						'stock_exceptions'   => array(
							'type'                 => 'object',
							'description'          => 'Product ID => expected quantity, or "unlimited", for products that legitimately differ, e.g. {"23511": 3, "23649": "unlimited"}.',
							'additionalProperties' => array( 'type' => array( 'integer', 'string' ) ),
						),
						'deadline_text'      => array(
							'type'        => 'string',
							'description' => 'Text every product in the categories should mention in its short description or body, e.g. "October 21, 2026".',
						),
						'menu'               => array(
							'type'        => array( 'integer', 'string' ),
							'description' => 'Menu ID, slug or name to check.',
						),
						'stale_text'         => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Strings that mean last year, e.g. ["2025", "October 22"]. Defaults to the previous year.',
						),
						'form_ids'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Gravity Forms IDs to check.',
						),
						'expected_recipient' => array(
							'type'        => 'string',
							'description' => 'Email every checked form and the WooCommerce new-order email should reach.',
						),
						'orders_since'       => array(
							'type'        => 'string',
							'description' => 'Report failed orders created on or after this date. Defaults to 1 September of the year.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'year'            => array( 'type' => 'integer' ),
						'needs_attention' => array( 'type' => 'integer' ),
						'sections'        => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_annual_reset_report' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		);
	}

	/**
	 * Gravity Forms abilities.
	 *
	 * @return array<string, array>
	 */
	private static function gravityforms_definitions() {
		return array(

			'gravityforms/export-entries' => array(
				'is_write'            => true,
				'requires'            => 'gravityforms',
				'label'               => 'Export Gravity Forms Entries',
				'description'         => 'Export a form\'s entries to a CSV with the form\'s own field labels as column headings. This is the archive step of a yearly reset and the key that unlocks gravityforms/trash-entries: trashing is refused unless the same entries were exported here first. The file is written to uploads/fdj-exports/ under an unguessable name, contains personal data, and is deleted automatically after 24 hours, so download it promptly. Up to 5000 entries per call; narrow with a date range for more. Pass inline_max_rows to also get the first rows back in the response.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'         => array(
							'type'        => 'integer',
							'description' => 'The Gravity Forms form ID.',
						),
						'status'          => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'spam', 'trash' ),
							'description' => 'Which entries to export. Defaults to active.',
							'default'     => 'active',
						),
						'date_start'      => array(
							'type'        => 'string',
							'description' => 'Only entries created on or after this date, e.g. "2022-01-01".',
						),
						'date_end'        => array(
							'type'        => 'string',
							'description' => 'Only entries created on or before this date. Use the same value you will pass to trash-entries.',
						),
						'inline_max_rows' => array(
							'type'        => 'integer',
							'description' => 'Also return up to this many rows as text in the response. 0 (default) returns none; capped at 200.',
							'default'     => 0,
						),
					),
					'required'   => array( 'form_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'      => array( 'type' => 'integer' ),
						'count'        => array( 'type' => 'integer' ),
						'file'         => array( 'type' => 'object' ),
						'csv_preview'  => array( 'type' => 'string' ),
						'unlocks_trash' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_export_entries' ),
				'permission_callback' => array( 'FDJ_MCP_GravityForms', 'can_view_gf_entries' ),
			),

			'gravityforms/trash-entries' => array(
				'is_write'            => true,
				'requires'            => 'gravityforms',
				'label'               => 'Trash Gravity Forms Entries',
				'description'         => 'Move a form\'s old entries to the Gravity Forms trash, so the entries list shows only this year\'s. Entries stay recoverable from the trash; nothing is permanently deleted. Guards, all enforced here: (1) date_end is required, there is no "everything" option; (2) the same entries must have been exported with gravityforms/export-entries within the last day; (3) entries created this calendar year are refused unless allow_current_year is true; (4) expected_count must equal the real number, so run dry_run first, read the count, then pass it back. Up to 2000 entries per call. Only trashes entries that are currently active (or the status you name).',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'            => array(
							'type'        => 'integer',
							'description' => 'The Gravity Forms form ID.',
						),
						'date_end'           => array(
							'type'        => 'string',
							'description' => 'Trash entries created on or before this date, e.g. "2025-12-31". Required.',
						),
						'date_start'         => array(
							'type'        => 'string',
							'description' => 'Optional lower bound, matching the export.',
						),
						'status'             => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'spam' ),
							'description' => 'Which entries to trash. Defaults to active.',
							'default'     => 'active',
						),
						'expected_count'     => array(
							'type'        => 'integer',
							'description' => 'The number of entries you expect this to trash. The call is refused if the real number differs. Not needed for dry_run.',
						),
						'allow_current_year' => array(
							'type'        => 'boolean',
							'description' => 'Permit trashing entries dated in the current calendar year. Off by default, because the usual mistake is clearing this year\'s real submissions.',
							'default'     => false,
						),
						'dry_run'            => array(
							'type'        => 'boolean',
							'description' => 'Report the count, date range and whether an export covers them, without trashing anything.',
							'default'     => false,
						),
					),
					'required'   => array( 'form_id', 'date_end' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'        => array( 'type' => 'integer' ),
						'dry_run'        => array( 'type' => 'boolean' ),
						'count'          => array( 'type' => 'integer' ),
						'oldest'         => array( 'type' => 'string' ),
						'newest'         => array( 'type' => 'string' ),
						'export_covers'  => array( 'type' => 'boolean' ),
						'trashed'        => array( 'type' => 'integer' ),
						'failed_ids'     => array( 'type' => 'array' ),
						'remaining'      => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_trash_entries' ),
				'permission_callback' => array( __CLASS__, 'can_delete_gf_entries' ),
			),

			'gravityforms/update-form' => array(
				'is_write'            => true,
				'requires'            => 'gravityforms',
				'label'               => 'Update Gravity Forms Form',
				'description'         => 'Edit a form\'s title, description, active state, field labels, descriptions, CSS classes and choices, remove fields, and change who a notification emails, in one call, with dry_run. The form is saved to a backup first (restore with gravityforms/restore-form), and the result is read back and compared with what you asked for. Built around the trap that hides fields silently: a checkbox, radio or select choice whose stored value equals its text. Change a choice\'s text here and its stored value follows, and every conditional-logic rule that matched the old value is rewritten to the new one, so a field shown "when the checkbox is X" does not vanish. Removing a field is refused if conditional logic, a calculation, a notification or a confirmation still refers to it, unless force_delete is true. To hide a field instead of deleting it, set css_class to "gf_hidden". It cannot create a form, delete a form, or change a field\'s type.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'               => array(
							'type'        => 'integer',
							'description' => 'The Gravity Forms form ID.',
						),
						'title'                 => array(
							'type'        => 'string',
							'description' => 'New form title.',
						),
						'description'           => array(
							'type'        => 'string',
							'description' => 'New form description. Pass an empty string to clear it.',
						),
						'is_active'             => array(
							'type'        => 'boolean',
							'description' => 'Activate or deactivate the form. A deactivated form stops accepting submissions but keeps its entries.',
						),
						'fields'                => array(
							'type'        => 'array',
							'description' => 'Field edits. Each item names a field by id and any of: label, description, css_class, required, choices (list of {from, text, value, price} matched by the choice\'s current text), delete.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'integer' ),
									'label'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
									'css_class'   => array( 'type' => 'string' ),
									'required'    => array( 'type' => 'boolean' ),
									'delete'      => array( 'type' => 'boolean' ),
									'choices'     => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'from'  => array( 'type' => 'string' ),
												'text'  => array( 'type' => 'string' ),
												'value' => array( 'type' => 'string' ),
												'price' => array( 'type' => 'string' ),
											),
										),
									),
								),
								'required'   => array( 'id' ),
							),
						),
						'replace_rule_values'   => array(
							'type'        => 'array',
							'description' => 'Extra conditional-logic value rewrites, {from, to}, applied to every rule in the form. Needed only for changes made outside this call; choice edits above already rewrite their own rules.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'from' => array( 'type' => 'string' ),
									'to'   => array( 'type' => 'string' ),
								),
							),
						),
						'notifications'         => array(
							'type'        => 'array',
							'description' => 'Notification edits. Each item names a notification by id (see gravityforms/get-form) and any of: to, bcc, subject, from_name, reply_to. "to" can only be changed on a notification that sends to a fixed email address, not one that sends to an email field.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'id'        => array( 'type' => 'string' ),
									'to'        => array( 'type' => 'string' ),
									'bcc'       => array( 'type' => 'string' ),
									'subject'   => array( 'type' => 'string' ),
									'from_name' => array( 'type' => 'string' ),
									'reply_to'  => array( 'type' => 'string' ),
								),
								'required'   => array( 'id' ),
							),
						),
						'force_delete'          => array(
							'type'        => 'boolean',
							'description' => 'Allow deleting a field that other settings still refer to. Off by default.',
							'default'     => false,
						),
						'dry_run'               => array(
							'type'        => 'boolean',
							'description' => 'Report every change that would be made, and any problem, without saving.',
							'default'     => false,
						),
					),
					'required'   => array( 'form_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'    => array( 'type' => 'integer' ),
						'dry_run'    => array( 'type' => 'boolean' ),
						'changes'    => array( 'type' => 'array' ),
						'backup_id'  => array( 'type' => 'string' ),
						'verified'   => array( 'type' => 'boolean' ),
						'mismatches' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_form' ),
				'permission_callback' => array( 'FDJ_MCP_GravityForms', 'can_view_gf_forms' ),
			),

			'gravityforms/restore-form' => array(
				'is_write'            => true,
				'requires'            => 'gravityforms',
				'label'               => 'Restore Gravity Forms Form',
				'description'         => 'Put a form back to a backup taken by gravityforms/update-form (or by an earlier restore). Backups are kept for the 25 most recent changes across all forms. The current state is backed up first, so a restore can itself be undone. Call with no backup_id to list the available backups.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'backup_id' => array(
							'type'        => 'string',
							'description' => 'A backup ID from update-form. Omit to list backups.',
						),
						'dry_run'   => array(
							'type'        => 'boolean',
							'description' => 'Report what would be restored without saving.',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'backups'   => array( 'type' => 'array' ),
						'restored'  => array( 'type' => 'boolean' ),
						'backup_id' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_restore_form' ),
				'permission_callback' => array( 'FDJ_MCP_GravityForms', 'can_view_gf_forms' ),
			),
		);
	}

	/**
	 * WooCommerce abilities.
	 *
	 * @return array<string, array>
	 */
	private static function woocommerce_definitions() {
		return array(

			'fdj/audit-products' => array(
				'is_write'            => false,
				'requires'            => 'woocommerce',
				'label'               => 'Audit Products',
				'description'         => 'A stock-and-status table for many products at once: title, status, price, whether stock is managed, quantity, stock status, backorders, sold individually, lifetime sales, categories, the Gravity Forms form attached to it, and (optionally) whether its short description or body mentions a given text. The native WooCommerce products ability returns a fixed catalog field set one query at a time and cannot see sold-individually, the attached form or draft products in bulk. Also lists products that share a title across statuses, which is how duplicate drafts show up.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'category_slugs' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Only products in these category slugs.',
						),
						'statuses'       => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'publish', 'draft', 'pending', 'private' ),
							),
							'description' => 'Which statuses to include. Defaults to all four.',
						),
						'search'         => array(
							'type'        => 'string',
							'description' => 'Only products whose title or content matches.',
						),
						'product_ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Only these product IDs.',
						),
						'mentions'       => array(
							'type'        => 'string',
							'description' => 'Add a column saying whether the short description or body contains this text, e.g. "October 21".',
						),
						'limit'          => array(
							'type'        => 'integer',
							'description' => 'Max products. Defaults to 100, capped at 300.',
							'default'     => 100,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'      => array( 'type' => 'integer' ),
						'products'   => array( 'type' => 'array' ),
						'duplicates' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_audit_products' ),
				'permission_callback' => array( __CLASS__, 'can_edit_products' ),
			),

			'fdj/set-product-fields' => array(
				'is_write'            => true,
				'requires'            => 'woocommerce',
				'label'               => 'Set Product Fields',
				'description'         => 'Change stock, status, title, price, backorders, sold-individually and the short description on up to 100 products in one call, with dry_run. Built for a seasonal reset: set every offered product to managed stock with a quantity of 1, switch retired products to draft, and add a deadline sentence to a short description. Each product is read back after saving and reported with old and new values. Status can only be publish, draft, pending or private; a product cannot be trashed or deleted here. short_description_append adds a paragraph only if it is not already there, so re-running is safe.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'products' => array(
							'type'        => 'array',
							'description' => 'Up to 100 product edits. Each names a product_id and any of the fields below. Only fields you pass change.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'product_id'                => array( 'type' => 'integer' ),
									'title'                     => array( 'type' => 'string' ),
									'status'                    => array(
										'type' => 'string',
										'enum' => array( 'publish', 'draft', 'pending', 'private' ),
									),
									'manage_stock'              => array( 'type' => 'boolean' ),
									'stock_quantity'            => array( 'type' => 'integer' ),
									'stock_status'              => array(
										'type' => 'string',
										'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
									),
									'backorders'                => array(
										'type' => 'string',
										'enum' => array( 'no', 'notify', 'yes' ),
									),
									'sold_individually'         => array( 'type' => 'boolean' ),
									'regular_price'             => array( 'type' => 'string' ),
									'short_description'         => array( 'type' => 'string' ),
									'short_description_append'  => array( 'type' => 'string' ),
								),
								'required'   => array( 'product_id' ),
							),
						),
						'dry_run'  => array(
							'type'        => 'boolean',
							'description' => 'Report every change without saving.',
							'default'     => false,
						),
					),
					'required'   => array( 'products' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'dry_run'  => array( 'type' => 'boolean' ),
						'results'  => array( 'type' => 'array' ),
						'verified' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_set_product_fields' ),
				'permission_callback' => array( __CLASS__, 'can_edit_products' ),
			),

			'fdj/list-order-items' => array(
				'is_write'            => false,
				'requires'            => 'woocommerce',
				'label'               => 'List Order Items',
				'description'         => 'One row per purchased item across many orders, with what a show catalog or a fulfilment list needs: order date and status, the buyer\'s first and last name, the product, quantity, line total, and every custom detail the buyer filled in on the product page (for example "Attribution: In memory of" and "Attribution Name", or a dog\'s name). The native WooCommerce orders ability returns only a billing email and no per-item details, so it cannot answer "who sponsored which trophy and in whose memory". Filter by date, status, product category or product. Buyer names are included because a catalog credits sponsors by name; email addresses are left out unless include_email is true, and phone numbers and street addresses are never returned. Also returns a per-product tally. Read-only; every call is written to the audit log.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'date_after'     => array(
							'type'        => 'string',
							'description' => 'Only orders created on or after this date, e.g. "2026-06-01".',
						),
						'date_before'    => array(
							'type'        => 'string',
							'description' => 'Only orders created on or before this date.',
						),
						'statuses'       => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
							),
							'description' => 'Order statuses to include. Defaults to processing, on-hold and completed, which are the paid ones.',
						),
						'category_slugs' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Only items whose product is in one of these product category slugs, e.g. ["trophy-donation"].',
						),
						'product_ids'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Only items for these product IDs.',
						),
						'include_email'  => array(
							'type'        => 'boolean',
							'description' => 'Also return the billing email. Off by default.',
							'default'     => false,
						),
						'limit'          => array(
							'type'        => 'integer',
							'description' => 'Max orders to read. Defaults to 200, capped at 500.',
							'default'     => 200,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'order_count' => array( 'type' => 'integer' ),
						'row_count'   => array( 'type' => 'integer' ),
						'rows'        => array( 'type' => 'array' ),
						'by_product'  => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_order_items' ),
				'permission_callback' => array( __CLASS__, 'can_view_orders' ),
			),

			'fdj/get-order-notes' => array(
				'is_write'            => false,
				'requires'            => 'woocommerce',
				'label'               => 'Get Order Notes',
				'description'         => 'Read the notes on WooCommerce orders, where a payment gateway records why a charge failed, or which emails were sent. Pass order_ids, or a status (for example "failed") with a since date to find orders first. Returns status, date, total, payment method, the item names and the notes. It never returns the customer\'s name, email or address.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'order_ids' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => 'Up to 25 order IDs.',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Find orders with this status, e.g. "failed", "processing", "on-hold". Used when order_ids is not given.',
						),
						'since'     => array(
							'type'        => 'string',
							'description' => 'With status: only orders created on or after this date.',
						),
						'limit'     => array(
							'type'        => 'integer',
							'description' => 'With status: max orders. Defaults to 10, capped at 25.',
							'default'     => 10,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'orders' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_order_notes' ),
				'permission_callback' => array( __CLASS__, 'can_view_orders' ),
			),
		);
	}

	/**
	 * Valid search locations.
	 *
	 * @return string[]
	 */
	public static function search_locations() {
		return array( 'title', 'content', 'excerpt', 'menus', 'widgets', 'forms' );
	}

	/* -----------------------------------------------------------------
	 * Permission callbacks
	 * ----------------------------------------------------------------- */

	/** @return bool */
	public static function can_edit_theme() {
		return current_user_can( 'edit_theme_options' ) || current_user_can( 'manage_options' );
	}

	/** @return bool */
	public static function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/** @return bool */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Same fallback reasoning as FDJ_MCP_GravityForms: GF's own capability first,
	 * manage_options for sites that never seeded GF's granular capabilities.
	 *
	 * @return bool
	 */
	public static function can_delete_gf_entries() {
		return current_user_can( 'gravityforms_delete_entries' ) || current_user_can( 'manage_options' );
	}

	/** @return bool */
	public static function can_edit_products() {
		return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/** @return bool */
	public static function can_view_orders() {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/* -----------------------------------------------------------------
	 * Small shared helpers (pure, so they can be tested without WordPress)
	 * ----------------------------------------------------------------- */

	/**
	 * Neutralise a value that a spreadsheet would run as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			// A leading apostrophe keeps Excel and Sheets from evaluating it.
			// A bare negative number or phone number is left alone.
			if ( ! preg_match( '/^[-+]?[0-9][0-9.,]*$/', $value ) ) {
				return "'" . $value;
			}
		}

		return $value;
	}

	/**
	 * Decide whether a trash request is allowed, given facts about the entries.
	 *
	 * Pure on purpose: this is the guard that protects this year's real
	 * submissions, so it is the part that most needs a test.
	 *
	 * @param array $facts {
	 *   @type int    $count              Entries that would be trashed.
	 *   @type int    $expected           expected_count from the caller, or -1 if absent.
	 *   @type int    $current_year_count Of those, how many are dated in the current year.
	 *   @type bool   $allow_current_year Caller override.
	 *   @type bool   $export_covers      Whether a recent export contains every one of them.
	 *   @type bool   $dry_run            Dry run.
	 * }
	 * @return array{ok: bool, code: string, message: string}
	 */
	public static function trash_decision( $facts ) {
		$count = (int) $facts['count'];

		if ( 0 === $count ) {
			return array( 'ok' => true, 'code' => 'nothing_to_do', 'message' => 'No entries match, nothing to trash.' );
		}

		if ( $count > 2000 ) {
			return array(
				'ok'      => false,
				'code'    => 'fdj_gf_too_many',
				'message' => sprintf( '%d entries match, which is more than the 2000 allowed in one call. Narrow the date range and run it in parts.', $count ),
			);
		}

		if ( (int) $facts['current_year_count'] > 0 && empty( $facts['allow_current_year'] ) ) {
			return array(
				'ok'      => false,
				'code'    => 'fdj_gf_current_year',
				'message' => sprintf( '%d of these entries were created this calendar year. That is almost always a mistake. Lower date_end, or pass allow_current_year: true if you really mean it.', (int) $facts['current_year_count'] ),
			);
		}

		if ( ! empty( $facts['dry_run'] ) ) {
			return array( 'ok' => true, 'code' => 'dry_run', 'message' => 'Dry run only.' );
		}

		if ( ! $facts['export_covers'] ) {
			return array(
				'ok'      => false,
				'code'    => 'fdj_gf_not_exported',
				'message' => 'These entries have not been exported in the last 24 hours. Run gravityforms/export-entries with the same form_id, status, date_start and date_end first, then try again.',
			);
		}

		if ( (int) $facts['expected'] !== $count ) {
			return array(
				'ok'      => false,
				'code'    => 'fdj_gf_count_mismatch',
				'message' => sprintf( 'expected_count is %d but %d entries match. Re-run with dry_run to confirm the number, then pass it back as expected_count.', (int) $facts['expected'], $count ),
			);
		}

		return array( 'ok' => true, 'code' => 'ok', 'message' => 'Allowed.' );
	}

	/**
	 * Apply a requested set of edits to a Gravity Forms form definition.
	 *
	 * Pure: takes the form as a plain array (fields as objects or arrays are both
	 * accepted), returns the new form, a list of human-readable changes and a
	 * list of problems. Nothing is saved here, so dry_run and the real write use
	 * the same code path and cannot disagree.
	 *
	 * @param array $form  The form.
	 * @param array $input The ability input.
	 * @return array{form: array, changes: array, errors: array}
	 */
	public static function compute_form_changes( $form, $input ) {
		$changes = array();
		$errors  = array();
		$rewrites = array();

		if ( isset( $input['title'] ) && (string) $input['title'] !== (string) rgar_fdj( $form, 'title' ) ) {
			$changes[]     = array( 'what' => 'title', 'old' => (string) rgar_fdj( $form, 'title' ), 'new' => (string) $input['title'] );
			$form['title'] = (string) $input['title'];
		}

		if ( isset( $input['description'] ) && (string) $input['description'] !== (string) rgar_fdj( $form, 'description' ) ) {
			$changes[]           = array( 'what' => 'description', 'old' => (string) rgar_fdj( $form, 'description' ), 'new' => (string) $input['description'] );
			$form['description'] = (string) $input['description'];
		}

		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		$edits = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();

		foreach ( $edits as $edit ) {
			$fid = isset( $edit['id'] ) ? (int) $edit['id'] : 0;
			$idx = null;

			foreach ( $fields as $i => $field ) {
				if ( (int) fdj_prop( $field, 'id' ) === $fid ) {
					$idx = $i;
					break;
				}
			}

			if ( null === $idx ) {
				$errors[] = sprintf( 'Field %d does not exist on this form.', $fid );
				continue;
			}

			$field = $fields[ $idx ];

			if ( ! empty( $edit['delete'] ) ) {
				$refs = self::field_references( $form, $fid );
				if ( $refs && empty( $input['force_delete'] ) ) {
					$errors[] = sprintf( 'Field %d is still used by: %s. Remove or update those first, hide the field with css_class "gf_hidden" instead, or pass force_delete.', $fid, implode( '; ', $refs ) );
					continue;
				}
				$changes[] = array( 'what' => 'delete field', 'field' => $fid, 'label' => (string) fdj_prop( $field, 'label' ), 'still_referenced_by' => $refs );
				unset( $fields[ $idx ] );
				continue;
			}

			$simple = array(
				'label'       => 'label',
				'description' => 'description',
				'css_class'   => 'cssClass',
			);

			foreach ( $simple as $in_key => $prop ) {
				if ( isset( $edit[ $in_key ] ) && (string) $edit[ $in_key ] !== (string) fdj_prop( $field, $prop ) ) {
					$changes[] = array( 'what' => $in_key, 'field' => $fid, 'old' => (string) fdj_prop( $field, $prop ), 'new' => (string) $edit[ $in_key ] );
					$field     = fdj_set_prop( $field, $prop, (string) $edit[ $in_key ] );
				}
			}

			if ( isset( $edit['required'] ) && (bool) $edit['required'] !== (bool) fdj_prop( $field, 'isRequired' ) ) {
				$changes[] = array( 'what' => 'required', 'field' => $fid, 'old' => (bool) fdj_prop( $field, 'isRequired' ), 'new' => (bool) $edit['required'] );
				$field     = fdj_set_prop( $field, 'isRequired', (bool) $edit['required'] );
			}

			if ( ! empty( $edit['choices'] ) && is_array( $edit['choices'] ) ) {
				$choices = fdj_prop( $field, 'choices' );
				$inputs  = fdj_prop( $field, 'inputs' );

				if ( ! is_array( $choices ) ) {
					$errors[] = sprintf( 'Field %d has no choices to edit.', $fid );
				} else {
					foreach ( $edit['choices'] as $ce ) {
						$from = isset( $ce['from'] ) ? (string) $ce['from'] : '';
						$ci   = null;

						foreach ( $choices as $k => $choice ) {
							if ( (string) $choice['text'] === $from ) {
								$ci = $k;
								break;
							}
						}

						if ( null === $ci ) {
							$errors[] = sprintf( 'Field %d has no choice with the text "%s".', $fid, $from );
							continue;
						}

						$old_text  = (string) $choices[ $ci ]['text'];
						$old_value = isset( $choices[ $ci ]['value'] ) ? (string) $choices[ $ci ]['value'] : $old_text;
						$new_text  = isset( $ce['text'] ) ? (string) $ce['text'] : $old_text;

						if ( isset( $ce['value'] ) ) {
							$new_value = (string) $ce['value'];
						} elseif ( $old_value === $old_text ) {
							// Stored value tracked the text. Keep it tracking, or every
							// existing rule and entry that matched it stops matching.
							$new_value = $new_text;
						} else {
							$new_value = $old_value;
						}

						$choices[ $ci ]['text']  = $new_text;
						$choices[ $ci ]['value'] = $new_value;

						if ( isset( $ce['price'] ) ) {
							$choices[ $ci ]['price'] = (string) $ce['price'];
						}

						// Checkbox fields keep a parallel list of inputs, one label per choice.
						if ( is_array( $inputs ) && isset( $inputs[ $ci ] ) && isset( $inputs[ $ci ]['label'] ) && $inputs[ $ci ]['label'] === $old_text ) {
							$inputs[ $ci ]['label'] = $new_text;
						}

						if ( $old_text !== $new_text || $old_value !== $new_value ) {
							$changes[] = array( 'what' => 'choice', 'field' => $fid, 'old_text' => $old_text, 'new_text' => $new_text, 'old_value' => $old_value, 'new_value' => $new_value );
						}

						if ( $old_value !== $new_value ) {
							$rewrites[ $old_value ] = $new_value;
						}
					}

					$field = fdj_set_prop( $field, 'choices', $choices );
					if ( is_array( $inputs ) ) {
						$field = fdj_set_prop( $field, 'inputs', $inputs );
					}
				}
			}

			$fields[ $idx ] = $field;
		}

		$form['fields'] = array_values( $fields );

		if ( ! empty( $input['replace_rule_values'] ) && is_array( $input['replace_rule_values'] ) ) {
			foreach ( $input['replace_rule_values'] as $r ) {
				if ( isset( $r['from'], $r['to'] ) ) {
					$rewrites[ (string) $r['from'] ] = (string) $r['to'];
				}
			}
		}

		if ( $rewrites ) {
			$count = 0;
			$form  = self::rewrite_rule_values( $form, $rewrites, $count );
			if ( $count > 0 ) {
				$changes[] = array( 'what' => 'conditional logic rules rewritten', 'count' => $count, 'rewrites' => $rewrites );
			}
		}

		if ( ! empty( $input['notifications'] ) && is_array( $input['notifications'] ) ) {
			$map = array(
				'to'        => 'to',
				'bcc'       => 'bcc',
				'subject'   => 'subject',
				'from_name' => 'fromName',
				'reply_to'  => 'replyTo',
			);

			foreach ( $input['notifications'] as $n ) {
				$nid = isset( $n['id'] ) ? (string) $n['id'] : '';

				if ( ! isset( $form['notifications'][ $nid ] ) ) {
					$errors[] = sprintf( 'Notification "%s" does not exist on this form.', $nid );
					continue;
				}

				foreach ( $map as $in_key => $prop ) {
					if ( ! isset( $n[ $in_key ] ) ) {
						continue;
					}

					if ( 'to' === $in_key && isset( $form['notifications'][ $nid ]['toType'] ) && 'email' !== $form['notifications'][ $nid ]['toType'] ) {
						$errors[] = sprintf( 'Notification "%s" sends to a %s, not a fixed address, so "to" cannot be changed here.', $nid, (string) $form['notifications'][ $nid ]['toType'] );
						continue;
					}

					$old = isset( $form['notifications'][ $nid ][ $prop ] ) ? (string) $form['notifications'][ $nid ][ $prop ] : '';
					$new = (string) $n[ $in_key ];

					if ( $old !== $new ) {
						$changes[] = array( 'what' => 'notification ' . $in_key, 'notification' => $nid, 'name' => isset( $form['notifications'][ $nid ]['name'] ) ? (string) $form['notifications'][ $nid ]['name'] : '', 'old' => $old, 'new' => $new );
						$form['notifications'][ $nid ][ $prop ] = $new;
					}
				}
			}
		}

		return array( 'form' => $form, 'changes' => $changes, 'errors' => $errors );
	}

	/**
	 * What still refers to a field: conditional logic, calculations, merge tags.
	 *
	 * @param array $form      The form.
	 * @param int   $field_id  The field being considered for deletion.
	 * @return string[]
	 */
	public static function field_references( $form, $field_id ) {
		$field_id = (int) $field_id;
		$refs     = array();

		foreach ( (array) $form['fields'] as $field ) {
			$fid = (int) fdj_prop( $field, 'id' );

			if ( $fid === $field_id ) {
				continue;
			}

			$logic = fdj_prop( $field, 'conditionalLogic' );
			if ( self::logic_mentions( $logic, $field_id ) ) {
				$refs[] = sprintf( 'conditional logic on field %d', $fid );
			}

			$calc = (string) fdj_prop( $field, 'calculationFormula' );
			if ( '' !== $calc && preg_match( '/:' . $field_id . '(\.\d+)?\}/', $calc ) ) {
				$refs[] = sprintf( 'a calculation on field %d', $fid );
			}

			if ( (int) fdj_prop( $field, 'productField' ) === $field_id ) {
				$refs[] = sprintf( 'field %d, which is priced from it', $fid );
			}
		}

		if ( isset( $form['button']['conditionalLogic'] ) && self::logic_mentions( $form['button']['conditionalLogic'], $field_id ) ) {
			$refs[] = 'conditional logic on the submit button';
		}

		foreach ( array( 'notifications' => 'notification', 'confirmations' => 'confirmation' ) as $key => $label ) {
			foreach ( isset( $form[ $key ] ) && is_array( $form[ $key ] ) ? $form[ $key ] : array() as $item ) {
				$text = wp_json_encode_fdj( $item );
				if ( preg_match( '/:' . $field_id . '(\.\d+)?(:[a-z_]+)?\}/', $text ) ) {
					$refs[] = sprintf( 'a merge tag in %s "%s"', $label, isset( $item['name'] ) ? $item['name'] : ( isset( $item['id'] ) ? $item['id'] : '?' ) );
				}
				if ( self::logic_mentions( isset( $item['conditionalLogic'] ) ? $item['conditionalLogic'] : null, $field_id ) ) {
					$refs[] = sprintf( 'conditional logic on %s "%s"', $label, isset( $item['name'] ) ? $item['name'] : ( isset( $item['id'] ) ? $item['id'] : '?' ) );
				}
			}
		}

		return array_values( array_unique( $refs ) );
	}

	/**
	 * Does a conditional-logic block have a rule on this field?
	 *
	 * @param mixed $logic Conditional logic array, or empty.
	 * @param int   $field_id Field ID.
	 * @return bool
	 */
	private static function logic_mentions( $logic, $field_id ) {
		if ( ! is_array( $logic ) || empty( $logic['rules'] ) ) {
			return false;
		}

		foreach ( (array) $logic['rules'] as $rule ) {
			if ( isset( $rule['fieldId'] ) && (int) $rule['fieldId'] === (int) $field_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rewrite rule values everywhere conditional logic can live in a form.
	 *
	 * @param array $form     The form.
	 * @param array $rewrites Old value => new value.
	 * @param int   $count    Out: number of rules changed.
	 * @return array
	 */
	private static function rewrite_rule_values( $form, $rewrites, &$count ) {
		$fix = function ( $logic ) use ( $rewrites, &$count ) {
			if ( ! is_array( $logic ) || empty( $logic['rules'] ) ) {
				return $logic;
			}
			foreach ( $logic['rules'] as $i => $rule ) {
				if ( isset( $rule['value'] ) && array_key_exists( (string) $rule['value'], $rewrites ) ) {
					$logic['rules'][ $i ]['value'] = $rewrites[ (string) $rule['value'] ];
					++$count;
				}
			}
			return $logic;
		};

		foreach ( (array) $form['fields'] as $i => $field ) {
			$logic = fdj_prop( $field, 'conditionalLogic' );
			if ( is_array( $logic ) ) {
				$form['fields'][ $i ] = fdj_set_prop( $field, 'conditionalLogic', $fix( $logic ) );
			}
		}

		if ( isset( $form['button']['conditionalLogic'] ) ) {
			$form['button']['conditionalLogic'] = $fix( $form['button']['conditionalLogic'] );
		}

		foreach ( array( 'notifications', 'confirmations' ) as $key ) {
			if ( ! isset( $form[ $key ] ) || ! is_array( $form[ $key ] ) ) {
				continue;
			}
			foreach ( $form[ $key ] as $id => $item ) {
				if ( isset( $item['conditionalLogic'] ) ) {
					$form[ $key ][ $id ]['conditionalLogic'] = $fix( $item['conditionalLogic'] );
				}
			}
		}

		return $form;
	}

	/**
	 * Problems with a menu's items, from a flat list of plain arrays.
	 *
	 * Pure so the rules can be tested. Each item: id, parent, title, url, object,
	 * object_id, linked_status (publish, draft, trash, missing, or '' when it is
	 * a custom link).
	 *
	 * @param array $items Items.
	 * @return array<int, array{item_id: int, title: string, issue: string}>
	 */
	public static function menu_issues( $items ) {
		$issues = array();
		$seen   = array();
		$parents = array();

		foreach ( $items as $it ) {
			if ( (int) $it['parent'] > 0 ) {
				$parents[ (int) $it['parent'] ] = true;
			}
		}

		foreach ( $items as $it ) {
			$id    = (int) $it['id'];
			$title = (string) $it['title'];
			$url   = (string) $it['url'];
			$path  = (string) wp_parse_url_path( $url );

			if ( '#' === $url || '' === $url ) {
				// A bare "#" is how a non-clickable group header is built, and is
				// fine when it has children under it. On its own it is a dead link.
				if ( empty( $parents[ $id ] ) ) {
					$issues[] = array( 'item_id' => $id, 'title' => $title, 'issue' => 'link goes nowhere ("#") and has no sub-items' );
				}
			}

			if ( false !== stripos( $path, 'coming-soon' ) ) {
				$issues[] = array( 'item_id' => $id, 'title' => $title, 'issue' => 'points at a "coming soon" placeholder' );
			}

			if ( preg_match( '#-[0-9]/?$#', $path ) && '' !== $path ) {
				$issues[] = array( 'item_id' => $id, 'title' => $title, 'issue' => 'URL ends in -2, -3 and so on: probably a duplicate page twin of another page' );
			}

			if ( isset( $it['linked_status'] ) && '' !== $it['linked_status'] && 'publish' !== $it['linked_status'] && 'private' !== $it['linked_status'] ) {
				$issues[] = array( 'item_id' => $id, 'title' => $title, 'issue' => sprintf( 'points at something that is %s, so it is a dead link on the live site', $it['linked_status'] ) );
			}

			$key = strtolower( trim( $title ) );
			if ( '' !== $key ) {
				if ( isset( $seen[ 't:' . $key ] ) ) {
					$issues[] = array( 'item_id' => $id, 'title' => $title, 'issue' => sprintf( 'same title as item %d', $seen[ 't:' . $key ] ) );
				} else {
					$seen[ 't:' . $key ] = $id;
				}
			}

			if ( '' !== $url && '#' !== $url ) {
				$ukey = untrailingslashit_fdj( $url );
				if ( isset( $seen[ 'u:' . $ukey ] ) ) {
					$issues[] = array( 'item_id' => $id, 'title' => $title, 'issue' => sprintf( 'same link as item %d', $seen[ 'u:' . $ukey ] ) );
				} else {
					$seen[ 'u:' . $ukey ] = $id;
				}
			}
		}

		return $issues;
	}

	/**
	 * Problems with one product, from a plain array.
	 *
	 * @param array $p      Product facts: id, title, manage_stock, stock_quantity, stock_status, mentions (bool|null).
	 * @param int   $expected Expected quantity.
	 * @param array $exceptions Product ID => quantity or "unlimited".
	 * @return string[]
	 */
	public static function product_issues( $p, $expected, $exceptions ) {
		$issues = array();
		$id     = (int) $p['id'];
		$want   = $expected;
		$unlimited = false;

		if ( isset( $exceptions[ $id ] ) || isset( $exceptions[ (string) $id ] ) ) {
			$ex = isset( $exceptions[ $id ] ) ? $exceptions[ $id ] : $exceptions[ (string) $id ];
			if ( 'unlimited' === $ex ) {
				$unlimited = true;
			} else {
				$want = (int) $ex;
			}
		}

		if ( $unlimited ) {
			if ( ! empty( $p['manage_stock'] ) ) {
				$issues[] = 'should be unlimited but stock is managed';
			}
			if ( 'outofstock' === $p['stock_status'] ) {
				$issues[] = 'shows out of stock but should be unlimited';
			}
		} else {
			if ( empty( $p['manage_stock'] ) ) {
				$issues[] = 'stock is not managed, so it can be bought more than once';
			} elseif ( (int) $p['stock_quantity'] !== (int) $want ) {
				$issues[] = sprintf( 'stock is %d, expected %d', (int) $p['stock_quantity'], (int) $want );
			}
			if ( 'outofstock' === $p['stock_status'] ) {
				$issues[] = 'shows out of stock (sold, or not reset)';
			}
		}

		if ( array_key_exists( 'mentions', $p ) && null !== $p['mentions'] && ! $p['mentions'] ) {
			$issues[] = 'short description and body do not mention the deadline text';
		}

		return $issues;
	}

	/* -----------------------------------------------------------------
	 * fdj/get-menu
	 * ----------------------------------------------------------------- */

	/**
	 * Resolve a menu argument (ID, slug, name) or theme location to a menu object.
	 *
	 * @param mixed  $menu     ID, slug or name.
	 * @param string $location Theme location slug.
	 * @return WP_Term|false
	 */
	private static function resolve_menu( $menu, $location = '' ) {
		if ( '' !== $location ) {
			$locations = get_nav_menu_locations();
			if ( isset( $locations[ $location ] ) ) {
				return wp_get_nav_menu_object( (int) $locations[ $location ] );
			}
			return false;
		}

		if ( null === $menu || '' === $menu ) {
			return false;
		}

		return wp_get_nav_menu_object( is_numeric( $menu ) ? (int) $menu : (string) $menu );
	}

	/**
	 * Flatten a menu's items into plain arrays, with the status of what each points at.
	 *
	 * @param WP_Term $menu Menu.
	 * @return array
	 */
	private static function menu_items_plain( $menu ) {
		$raw   = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
		$items = array();

		foreach ( (array) $raw as $it ) {
			$linked = '';

			if ( 'post_type' === $it->type ) {
				$st     = get_post_status( (int) $it->object_id );
				$linked = $st ? $st : 'missing';
			}

			$items[] = array(
				'id'            => (int) $it->ID,
				'parent'        => (int) $it->menu_item_parent,
				'position'      => (int) $it->menu_order,
				'type'          => (string) $it->type,
				'object'        => (string) $it->object,
				'object_id'     => (int) $it->object_id,
				'title'         => (string) $it->title,
				'url'           => (string) $it->url,
				'classes'       => array_values( array_filter( (array) $it->classes ) ),
				'target'        => (string) $it->target,
				'linked_status' => $linked,
			);
		}

		return $items;
	}

	/**
	 * Read one menu, or list them.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_menu( $input = array() ) {
		$menu_arg = isset( $input['menu'] ) ? $input['menu'] : null;
		$location = isset( $input['location'] ) ? (string) $input['location'] : '';

		if ( null === $menu_arg && '' === $location ) {
			$locations = get_nav_menu_locations();
			$out       = array();

			foreach ( wp_get_nav_menus() as $m ) {
				$out[] = array(
					'id'        => (int) $m->term_id,
					'name'      => $m->name,
					'slug'      => $m->slug,
					'count'     => (int) $m->count,
					'locations' => array_keys( array_filter( $locations, function ( $id ) use ( $m ) {
						return (int) $id === (int) $m->term_id;
					} ) ),
				);
			}

			return array( 'menus' => $out );
		}

		$menu = self::resolve_menu( $menu_arg, $location );

		if ( ! $menu || is_wp_error( $menu ) ) {
			return new WP_Error( 'fdj_menu_not_found', 'No menu found for that menu or location. Call with no arguments to list menus.' );
		}

		$locations = get_nav_menu_locations();
		$items     = self::menu_items_plain( $menu );

		return array(
			'menu'   => array(
				'id'        => (int) $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'locations' => array_keys( array_filter( $locations, function ( $id ) use ( $menu ) {
					return (int) $id === (int) $menu->term_id;
				} ) ),
			),
			'items'  => $items,
			'issues' => self::menu_issues( $items ),
		);
	}

	/* -----------------------------------------------------------------
	 * fdj/search-everywhere
	 * ----------------------------------------------------------------- */

	/**
	 * Find text in every place a site keeps it.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_search_everywhere( $input = array() ) {
		global $wpdb;

		$search = isset( $input['search'] ) ? (string) $input['search'] : '';

		if ( strlen( $search ) < 3 ) {
			return new WP_Error( 'fdj_search_too_short', 'search must be at least 3 characters.' );
		}

		$limit     = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50;
		$locations = isset( $input['locations'] ) && is_array( $input['locations'] ) ? array_intersect( $input['locations'], self::search_locations() ) : self::search_locations();
		$like      = '%' . $wpdb->esc_like( $search ) . '%';
		$results   = array();
		$total     = 0;

		$skip_types = "('revision','nav_menu_item','auto-draft','customize_changeset','oembed_cache')";
		$skip_state = "('trash','auto-draft','inherit')";

		foreach ( array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
		) as $loc => $col ) {
			if ( ! in_array( $loc, $locations, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column and lists are fixed literals above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE post_type NOT IN {$skip_types} AND post_status NOT IN {$skip_state} AND {$col} LIKE %s ORDER BY post_modified DESC LIMIT %d", $like, $limit ) );

			$results[ $loc ] = array();
			foreach ( (array) $rows as $r ) {
				$results[ $loc ][] = array(
					'post_id' => (int) $r->ID,
					'type'    => $r->post_type,
					'status'  => $r->post_status,
					'title'   => $r->post_title,
				);
			}
			$total += count( $results[ $loc ] );
		}

		if ( in_array( 'menus', $locations, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names only.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT p.ID, p.post_title, m.meta_value AS url FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_menu_item_url' WHERE p.post_type = 'nav_menu_item' AND p.post_status <> 'trash' AND ( p.post_title LIKE %s OR m.meta_value LIKE %s ) LIMIT %d", $like, $like, $limit ) );

			$results['menus'] = array();
			foreach ( (array) $rows as $r ) {
				$terms = wp_get_object_terms( (int) $r->ID, 'nav_menu', array( 'fields' => 'names' ) );
				$results['menus'][] = array(
					'menu_item_id' => (int) $r->ID,
					'menu'         => is_wp_error( $terms ) ? array() : $terms,
					'title'        => $r->post_title,
					'url'          => (string) $r->url,
				);
			}
			$total += count( $results['menus'] );
		}

		if ( in_array( 'widgets', $locations, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s LIMIT %d", $wpdb->esc_like( 'widget_' ) . '%', $like, $limit ) );

			$results['widgets'] = array_map( function ( $n ) {
				return array( 'option' => $n );
			}, (array) $rows );
			$total += count( $results['widgets'] );
		}

		if ( in_array( 'forms', $locations, true ) && class_exists( 'GFAPI' ) ) {
			$results['forms'] = self::search_forms( $search, $limit );
			$total           += count( $results['forms'] );
		}

		return array(
			'search'  => $search,
			'results' => $results ? $results : new stdClass(),
			'total'   => $total,
		);
	}

	/**
	 * Find text in Gravity Forms definitions.
	 *
	 * @param string $search Text.
	 * @param int    $limit  Max hits.
	 * @return array
	 */
	private static function search_forms( $search, $limit ) {
		$hits = array();
		$has  = function ( $text ) use ( $search ) {
			return is_string( $text ) && false !== stripos( $text, $search );
		};

		foreach ( (array) GFAPI::get_forms( null ) as $form ) {
			$where = array();

			if ( $has( rgar_fdj( $form, 'title' ) ) ) {
				$where[] = 'form title';
			}
			if ( $has( rgar_fdj( $form, 'description' ) ) ) {
				$where[] = 'form description';
			}

			foreach ( (array) $form['fields'] as $field ) {
				$fid = fdj_prop( $field, 'id' );
				foreach ( array( 'label', 'description', 'content', 'placeholder' ) as $prop ) {
					if ( $has( fdj_prop( $field, $prop ) ) ) {
						$where[] = sprintf( 'field %d %s', (int) $fid, $prop );
					}
				}
				foreach ( (array) fdj_prop( $field, 'choices' ) as $c ) {
					if ( is_array( $c ) && ( $has( isset( $c['text'] ) ? $c['text'] : '' ) || $has( isset( $c['value'] ) ? $c['value'] : '' ) ) ) {
						$where[] = sprintf( 'field %d choice', (int) $fid );
					}
				}
			}

			foreach ( array( 'notifications' => 'notification', 'confirmations' => 'confirmation' ) as $key => $label ) {
				foreach ( isset( $form[ $key ] ) && is_array( $form[ $key ] ) ? $form[ $key ] : array() as $item ) {
					foreach ( array( 'to', 'bcc', 'subject', 'message', 'replyTo', 'fromName', 'url' ) as $prop ) {
						if ( isset( $item[ $prop ] ) && $has( $item[ $prop ] ) ) {
							$where[] = sprintf( '%s "%s" %s', $label, isset( $item['name'] ) ? $item['name'] : '?', $prop );
						}
					}
				}
			}

			if ( $where ) {
				$hits[] = array(
					'form_id' => (int) rgar_fdj( $form, 'id' ),
					'title'   => (string) rgar_fdj( $form, 'title' ),
					'where'   => array_values( array_unique( $where ) ),
				);
			}

			if ( count( $hits ) >= $limit ) {
				break;
			}
		}

		return $hits;
	}

	/* -----------------------------------------------------------------
	 * gravityforms/export-entries
	 * ----------------------------------------------------------------- */

	/**
	 * Build GFAPI search criteria from our date and status inputs.
	 *
	 * @param array  $input  Input with optional date_start, date_end.
	 * @param string $status Entry status.
	 * @return array
	 */
	private static function entry_criteria( $input, $status ) {
		$criteria = array( 'status' => $status );

		// A bare date means the whole day: start at its first second, end at its last.
		if ( ! empty( $input['date_start'] ) ) {
			$d = trim( (string) $input['date_start'] );
			$criteria['start_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d . ' 00:00:00' : $d;
		}

		if ( ! empty( $input['date_end'] ) ) {
			$d = trim( (string) $input['date_end'] );
			$criteria['end_date'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d . ' 23:59:59' : $d;
		}

		return $criteria;
	}

	/**
	 * Fetch every entry matching criteria, in pages, up to the limit.
	 *
	 * @param int   $form_id  Form ID.
	 * @param array $criteria Search criteria.
	 * @return array|WP_Error
	 */
	private static function fetch_entries( $form_id, $criteria ) {
		$total = (int) GFAPI::count_entries( $form_id, $criteria );

		if ( $total > self::ENTRY_LIMIT ) {
			return new WP_Error( 'fdj_gf_too_many', sprintf( '%d entries match, which is more than the %d allowed in one call. Narrow the date range and run it in parts.', $total, self::ENTRY_LIMIT ) );
		}

		$entries = array();
		$offset  = 0;
		$size    = 200;

		while ( count( $entries ) < $total ) {
			$page = GFAPI::get_entries(
				$form_id,
				$criteria,
				array( 'key' => 'date_created', 'direction' => 'ASC' ),
				array( 'offset' => $offset, 'page_size' => $size )
			);

			if ( is_wp_error( $page ) ) {
				return $page;
			}

			if ( empty( $page ) ) {
				break;
			}

			$entries = array_merge( $entries, $page );
			$offset += $size;
		}

		return $entries;
	}

	/**
	 * Column list for an export: entry meta, then every input with its label.
	 *
	 * @param array $form The form.
	 * @return array<string, string> Column key => heading.
	 */
	private static function export_columns( $form ) {
		$cols = array(
			'id'             => 'Entry ID',
			'date_created'   => 'Date',
			'status'         => 'Status',
			'source_url'     => 'Source URL',
			'payment_status' => 'Payment status',
			'payment_amount' => 'Payment amount',
			'transaction_id' => 'Transaction ID',
		);

		foreach ( (array) $form['fields'] as $field ) {
			$type = (string) fdj_prop( $field, 'type' );

			if ( in_array( $type, array( 'section', 'html', 'page', 'captcha' ), true ) ) {
				continue;
			}

			$label  = (string) fdj_prop( $field, 'label' );
			$inputs = fdj_prop( $field, 'inputs' );

			if ( is_array( $inputs ) && $inputs && ! in_array( $type, array( 'list' ), true ) ) {
				foreach ( $inputs as $in ) {
					if ( ! empty( $in['isHidden'] ) ) {
						continue;
					}
					$cols[ (string) $in['id'] ] = $label . ( empty( $in['label'] ) ? '' : ' (' . $in['label'] . ')' );
				}
			} else {
				$cols[ (string) fdj_prop( $field, 'id' ) ] = $label;
			}
		}

		return $cols;
	}

	/**
	 * Export entries to CSV.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_export_entries( $input = array() ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		$form    = GFAPI::get_form( $form_id );

		if ( ! $form || is_wp_error( $form ) ) {
			return new WP_Error( 'fdj_gf_form_not_found', sprintf( 'No Gravity Forms form found with ID %d.', $form_id ) );
		}

		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'active';
		$criteria = self::entry_criteria( $input, $status );
		$entries  = self::fetch_entries( $form_id, $criteria );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$cols = self::export_columns( (array) $form );
		$fh   = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, array_values( $cols ) );

		foreach ( $entries as $entry ) {
			$row = array();
			foreach ( array_keys( $cols ) as $key ) {
				$row[] = self::csv_safe( rgar_fdj( $entry, (string) $key ) );
			}
			fputcsv( $fh, $row );
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		$ids = array_map( function ( $e ) {
			return (int) $e['id'];
		}, $entries );

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'fdj-exports';
		$file   = null;

		if ( wp_mkdir_p( $dir ) ) {
			// Belt for hosts that read .htaccess; the unguessable name is the real
			// protection on nginx, and the file is deleted after a day either way.
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
			}

			$name = sprintf( 'form-%d-%s-%s.csv', $form_id, gmdate( 'Ymd-His' ), wp_generate_password( 16, false ) );
			$path = $dir . '/' . $name;

			if ( false !== file_put_contents( $path, $csv ) ) {
				$file = array(
					'name'       => $name,
					'path'       => 'wp-content/uploads/fdj-exports/' . $name,
					'url'        => trailingslashit( $upload['baseurl'] ) . 'fdj-exports/' . $name,
					'bytes'      => strlen( $csv ),
					'deleted_at' => gmdate( 'c', time() + DAY_IN_SECONDS ),
				);
				wp_schedule_single_event( time() + DAY_IN_SECONDS, self::CLEANUP_HOOK, array( $path ) );
			}
		}

		// Remember exactly which entries were exported, so trash-entries can prove
		// it is only touching entries that have an archive.
		set_transient( 'fdj_gf_export_' . $form_id, array( 'time' => time(), 'ids' => $ids ), DAY_IN_SECONDS );

		$out = array(
			'form_id'       => $form_id,
			'title'         => (string) rgar_fdj( $form, 'title' ),
			'status'        => $status,
			'count'         => count( $entries ),
			'columns'       => count( $cols ),
			'unlocks_trash' => true,
		);

		// Left out when it could not be written: a null here would fail the
		// ability's own output schema, which expects an object.
		if ( null !== $file ) {
			$out['file'] = $file;
		}

		$inline = isset( $input['inline_max_rows'] ) ? max( 0, min( 200, (int) $input['inline_max_rows'] ) ) : 0;
		if ( $inline > 0 ) {
			$lines             = explode( "\n", trim( $csv ) );
			$out['csv_preview'] = implode( "\n", array_slice( $lines, 0, $inline + 1 ) );
		}

		if ( null === $file ) {
			$out['warning'] = 'The entries were read and remembered for trash-entries, but the CSV could not be written to uploads/fdj-exports/. Pass inline_max_rows to get rows in the response, or fix the folder permissions, before relying on this as an archive.';
		}

		return $out;
	}

	/**
	 * Delete an export file. Runs from a scheduled event.
	 *
	 * @param string $path Absolute path.
	 */
	public static function delete_export_file( $path ) {
		$upload = wp_upload_dir();
		$root   = realpath( trailingslashit( $upload['basedir'] ) . 'fdj-exports' );
		$real   = realpath( (string) $path );

		// Only ever delete a file inside the exports folder.
		if ( $root && $real && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) && is_file( $real ) ) {
			wp_delete_file( $real );
		}
	}

	/* -----------------------------------------------------------------
	 * gravityforms/trash-entries
	 * ----------------------------------------------------------------- */

	/**
	 * Move old entries to the trash, behind the guards.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_trash_entries( $input = array() ) {
		$form_id  = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		$dry_run  = ! empty( $input['dry_run'] );
		$form     = GFAPI::get_form( $form_id );

		if ( ! $form || is_wp_error( $form ) ) {
			return new WP_Error( 'fdj_gf_form_not_found', sprintf( 'No Gravity Forms form found with ID %d.', $form_id ) );
		}

		if ( empty( $input['date_end'] ) ) {
			return new WP_Error( 'fdj_gf_date_required', 'date_end is required. There is no "trash everything" option.' );
		}

		$status   = isset( $input['status'] ) ? (string) $input['status'] : 'active';
		$criteria = self::entry_criteria( $input, $status );
		$entries  = self::fetch_entries( $form_id, $criteria );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$ids   = array();
		$dates = array();
		$year  = (int) wp_date( 'Y' );
		$this_year = 0;

		foreach ( $entries as $e ) {
			$ids[]   = (int) $e['id'];
			$dates[] = (string) $e['date_created'];
			if ( (int) substr( (string) $e['date_created'], 0, 4 ) >= $year ) {
				++$this_year;
			}
		}

		$export = get_transient( 'fdj_gf_export_' . $form_id );
		$covers = is_array( $export ) && ! empty( $export['ids'] ) && ! array_diff( $ids, $export['ids'] );

		$decision = self::trash_decision(
			array(
				'count'              => count( $ids ),
				'expected'           => isset( $input['expected_count'] ) ? (int) $input['expected_count'] : -1,
				'current_year_count' => $this_year,
				'allow_current_year' => ! empty( $input['allow_current_year'] ),
				'export_covers'      => $covers,
				'dry_run'            => $dry_run,
			)
		);

		if ( ! $decision['ok'] ) {
			return new WP_Error( $decision['code'], $decision['message'] );
		}

		sort( $dates );

		$out = array(
			'form_id'       => $form_id,
			'title'         => (string) rgar_fdj( $form, 'title' ),
			'dry_run'       => $dry_run,
			'count'         => count( $ids ),
			'oldest'        => $dates ? $dates[0] : '',
			'newest'        => $dates ? $dates[ count( $dates ) - 1 ] : '',
			'this_year'     => $this_year,
			'export_covers' => $covers,
		);

		if ( $dry_run || 0 === count( $ids ) ) {
			return $out;
		}

		$failed = array();
		foreach ( $ids as $id ) {
			$r = GFAPI::update_entry_property( $id, 'status', 'trash' );
			if ( ! $r || is_wp_error( $r ) ) {
				$failed[] = $id;
			}
		}

		// Read back: how many that matched before still match now?
		$left = (int) GFAPI::count_entries( $form_id, $criteria );

		$out['trashed']    = count( $ids ) - count( $failed );
		$out['failed_ids'] = $failed;
		$out['remaining']  = $left;
		$out['verified']   = ( 0 === $left && empty( $failed ) );

		return $out;
	}

	/* -----------------------------------------------------------------
	 * gravityforms/update-form and restore-form
	 * ----------------------------------------------------------------- */

	/**
	 * Save a copy of a form before changing it.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $form    The form as it is now.
	 * @param string $why    Short reason.
	 * @return string Backup ID.
	 */
	private static function backup_form( $form_id, $form, $why ) {
		$backups = get_option( self::BACKUP_OPTION, array() );
		if ( ! is_array( $backups ) ) {
			$backups = array();
		}

		$user = wp_get_current_user();
		$id   = gmdate( 'YmdHis' ) . '-' . $form_id . '-' . wp_generate_password( 4, false );

		array_unshift(
			$backups,
			array(
				'id'      => $id,
				'form_id' => (int) $form_id,
				'title'   => (string) rgar_fdj( $form, 'title' ),
				'time'    => time(),
				'user'    => $user && $user->ID ? $user->user_login : '(none)',
				'why'     => $why,
				'form'    => wp_json_encode( $form ),
			)
		);

		// autoload=no: this can be large and is read only by the abilities here.
		update_option( self::BACKUP_OPTION, array_slice( $backups, 0, self::BACKUP_LIMIT ), false );

		return $id;
	}

	/**
	 * Edit a form.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_form( $input = array() ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		$dry_run = ! empty( $input['dry_run'] );
		$form    = GFAPI::get_form( $form_id );

		if ( ! $form || is_wp_error( $form ) ) {
			return new WP_Error( 'fdj_gf_form_not_found', sprintf( 'No Gravity Forms form found with ID %d.', $form_id ) );
		}

		$form = (array) $form;

		$result = self::compute_form_changes( $form, $input );

		if ( $result['errors'] ) {
			return new WP_Error( 'fdj_gf_bad_edit', implode( ' ', $result['errors'] ) . ' Nothing was changed.' );
		}

		$wants_active = array_key_exists( 'is_active', $input ) ? (bool) $input['is_active'] : null;
		$was_active   = ! empty( $form['is_active'] );
		$active_change = null;

		if ( null !== $wants_active && $wants_active !== $was_active ) {
			$active_change = array( 'what' => 'is_active', 'old' => $was_active, 'new' => $wants_active );
			$result['changes'][] = $active_change;
		}

		$out = array(
			'form_id' => $form_id,
			'title'   => (string) rgar_fdj( $form, 'title' ),
			'dry_run' => $dry_run,
			'changes' => $result['changes'],
		);

		if ( $dry_run || ! $result['changes'] ) {
			if ( ! $result['changes'] ) {
				$out['note'] = 'Nothing to change: the form already matches.';
			}
			return $out;
		}

		$out['backup_id'] = self::backup_form( $form_id, $form, 'update-form' );

		$saved = GFAPI::update_form( $result['form'], $form_id );
		if ( is_wp_error( $saved ) || ! $saved ) {
			return new WP_Error( 'fdj_gf_update_failed', sprintf( 'Gravity Forms refused the update. The form is unchanged; backup %s was taken anyway.', $out['backup_id'] ) );
		}

		if ( $active_change ) {
			GFAPI::update_form_property( $form_id, 'is_active', $wants_active ? 1 : 0 );
		}

		// Read back from storage and compare, rather than trusting the call returned true.
		$after      = (array) GFAPI::get_form( $form_id );
		$mismatches = array();

		$check = self::compute_form_changes( $after, $input );
		foreach ( $check['changes'] as $c ) {
			// Anything still pending after the write did not stick. A conditional
			// rewrite re-applied to an already-rewritten form changes nothing, so
			// it never appears here; a real miss does.
			$mismatches[] = $c;
		}

		if ( $active_change ) {
			$now_active = ! empty( ( (array) GFAPI::get_form( $form_id ) )['is_active'] );
			if ( $now_active !== $wants_active ) {
				$mismatches[] = array( 'what' => 'is_active', 'expected' => $wants_active, 'stored' => $now_active );
			}
		}

		$out['verified']   = empty( $mismatches );
		$out['mismatches'] = $mismatches;

		return $out;
	}

	/**
	 * Restore a form from a backup, or list backups.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_restore_form( $input = array() ) {
		$backups = get_option( self::BACKUP_OPTION, array() );
		if ( ! is_array( $backups ) ) {
			$backups = array();
		}

		$bid = isset( $input['backup_id'] ) ? (string) $input['backup_id'] : '';

		if ( '' === $bid ) {
			return array(
				'backups' => array_map( function ( $b ) {
					return array(
						'backup_id' => $b['id'],
						'form_id'   => $b['form_id'],
						'title'     => $b['title'],
						'time'      => gmdate( 'c', (int) $b['time'] ),
						'user'      => $b['user'],
						'why'       => $b['why'],
					);
				}, $backups ),
			);
		}

		$found = null;
		foreach ( $backups as $b ) {
			if ( $b['id'] === $bid ) {
				$found = $b;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'fdj_gf_backup_not_found', 'No backup with that ID. Call with no backup_id to list them. Only the 25 most recent are kept.' );
		}

		$form_id = (int) $found['form_id'];
		$current = GFAPI::get_form( $form_id );

		if ( ! $current || is_wp_error( $current ) ) {
			return new WP_Error( 'fdj_gf_form_not_found', sprintf( 'Form %d no longer exists, so there is nothing to restore into.', $form_id ) );
		}

		$restored = json_decode( $found['form'], true );
		if ( ! is_array( $restored ) ) {
			return new WP_Error( 'fdj_gf_backup_corrupt', 'That backup could not be read.' );
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'restored'  => false,
				'dry_run'   => true,
				'backup_id' => $bid,
				'form_id'   => $form_id,
				'title'     => $found['title'],
			);
		}

		$new_backup = self::backup_form( $form_id, (array) $current, 'before restore of ' . $bid );

		$saved = GFAPI::update_form( $restored, $form_id );
		if ( is_wp_error( $saved ) || ! $saved ) {
			return new WP_Error( 'fdj_gf_restore_failed', 'Gravity Forms refused the restore. The form is unchanged.' );
		}

		$after = (array) GFAPI::get_form( $form_id );

		return array(
			'restored'      => ( (string) rgar_fdj( $after, 'title' ) === (string) rgar_fdj( $restored, 'title' ) && count( (array) $after['fields'] ) === count( (array) $restored['fields'] ) ),
			'backup_id'     => $bid,
			'undo_backup_id' => $new_backup,
			'form_id'       => $form_id,
			'title'         => (string) rgar_fdj( $after, 'title' ),
		);
	}

	/* -----------------------------------------------------------------
	 * fdj/audit-products
	 * ----------------------------------------------------------------- */

	/**
	 * Product facts as a plain array.
	 *
	 * @param WC_Product $p        Product.
	 * @param string     $mentions Optional text to look for.
	 * @return array
	 */
	private static function product_facts( $p, $mentions = '' ) {
		$id    = (int) $p->get_id();
		$facts = array(
			'id'                => $id,
			'title'             => $p->get_name(),
			'status'            => $p->get_status(),
			'type'              => $p->get_type(),
			'price'             => (string) $p->get_regular_price(),
			'manage_stock'      => (bool) $p->get_manage_stock(),
			'stock_quantity'    => null === $p->get_stock_quantity() ? null : (int) $p->get_stock_quantity(),
			'stock_status'      => $p->get_stock_status(),
			'backorders'        => $p->get_backorders(),
			'sold_individually' => (bool) $p->get_sold_individually(),
			'total_sales'       => (int) $p->get_total_sales(),
			'categories'        => wp_list_pluck( (array) get_the_terms( $id, 'product_cat' ), 'slug' ),
			'form_id'           => (int) get_post_meta( $id, '_gravity_form_id', true ),
			'modified'          => (string) get_post_field( 'post_modified', $id ),
		);

		if ( '' !== $mentions ) {
			$facts['mentions'] = ( false !== stripos( (string) $p->get_short_description(), $mentions ) )
				|| ( false !== stripos( (string) $p->get_description(), $mentions ) );
		}

		return $facts;
	}

	/**
	 * Stock and status table for many products.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_audit_products( $input = array() ) {
		$statuses = isset( $input['statuses'] ) && is_array( $input['statuses'] ) ? $input['statuses'] : array( 'publish', 'draft', 'pending', 'private' );
		$limit    = isset( $input['limit'] ) ? max( 1, min( 300, (int) $input['limit'] ) ) : 100;

		$args = array(
			'status'  => $statuses,
			'limit'   => $limit,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		if ( ! empty( $input['category_slugs'] ) ) {
			$args['category'] = array_map( 'sanitize_title', (array) $input['category_slugs'] );
		}
		if ( ! empty( $input['product_ids'] ) ) {
			$args['include'] = array_map( 'intval', (array) $input['product_ids'] );
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}

		$mentions = isset( $input['mentions'] ) ? (string) $input['mentions'] : '';
		$rows     = array();
		$by_title = array();

		foreach ( (array) wc_get_products( $args ) as $p ) {
			$facts  = self::product_facts( $p, $mentions );
			$rows[] = $facts;
			$by_title[ strtolower( trim( $facts['title'] ) ) ][] = array( 'id' => $facts['id'], 'status' => $facts['status'] );
		}

		$dupes = array();
		foreach ( $by_title as $title => $list ) {
			if ( count( $list ) > 1 ) {
				$dupes[] = array( 'title' => $title, 'products' => $list );
			}
		}

		return array(
			'count'      => count( $rows ),
			'truncated'  => count( $rows ) >= $limit,
			'products'   => $rows,
			'duplicates' => $dupes,
		);
	}

	/* -----------------------------------------------------------------
	 * fdj/set-product-fields
	 * ----------------------------------------------------------------- */

	/**
	 * Apply product edits.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_set_product_fields( $input = array() ) {
		$items   = isset( $input['products'] ) && is_array( $input['products'] ) ? $input['products'] : array();
		$dry_run = ! empty( $input['dry_run'] );

		if ( ! $items ) {
			return new WP_Error( 'fdj_product_none', 'products is empty.' );
		}

		if ( count( $items ) > 100 ) {
			return new WP_Error( 'fdj_product_too_many', 'At most 100 products per call.' );
		}

		$results  = array();
		$verified = true;

		// Check every product first, so a typo in item 40 does not leave 39 changed.
		foreach ( $items as $it ) {
			$id = isset( $it['product_id'] ) ? (int) $it['product_id'] : 0;
			$p  = $id ? wc_get_product( $id ) : false;

			if ( ! $p ) {
				return new WP_Error( 'fdj_product_not_found', sprintf( 'No product with ID %d. Nothing was changed.', $id ) );
			}

			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'fdj_product_forbidden', sprintf( 'You may not edit product %d. Nothing was changed.', $id ) );
			}
		}

		foreach ( $items as $it ) {
			$id  = (int) $it['product_id'];
			$p   = wc_get_product( $id );
			$chg = array();

			if ( isset( $it['title'] ) && (string) $it['title'] !== $p->get_name() ) {
				$chg['title'] = array( $p->get_name(), (string) $it['title'] );
				$p->set_name( (string) $it['title'] );
			}

			if ( isset( $it['status'] ) && (string) $it['status'] !== $p->get_status() ) {
				if ( ! in_array( $it['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
					return new WP_Error( 'fdj_product_bad_status', 'status must be publish, draft, pending or private. Products cannot be trashed here.' );
				}
				$chg['status'] = array( $p->get_status(), (string) $it['status'] );
				$p->set_status( (string) $it['status'] );
			}

			$wants_manage = array_key_exists( 'manage_stock', $it ) ? (bool) $it['manage_stock'] : null;
			if ( isset( $it['stock_quantity'] ) && null === $wants_manage ) {
				// A quantity only means something on a managed-stock product.
				$wants_manage = true;
			}

			if ( null !== $wants_manage && $wants_manage !== (bool) $p->get_manage_stock() ) {
				$chg['manage_stock'] = array( (bool) $p->get_manage_stock(), $wants_manage );
				$p->set_manage_stock( $wants_manage );
			}

			if ( isset( $it['stock_quantity'] ) && (int) $it['stock_quantity'] !== (int) $p->get_stock_quantity() ) {
				$chg['stock_quantity'] = array( $p->get_stock_quantity(), (int) $it['stock_quantity'] );
				$p->set_stock_quantity( (int) $it['stock_quantity'] );
			}

			if ( isset( $it['stock_status'] ) && (string) $it['stock_status'] !== $p->get_stock_status() ) {
				$chg['stock_status'] = array( $p->get_stock_status(), (string) $it['stock_status'] );
				$p->set_stock_status( (string) $it['stock_status'] );
			}

			if ( isset( $it['backorders'] ) && (string) $it['backorders'] !== $p->get_backorders() ) {
				$chg['backorders'] = array( $p->get_backorders(), (string) $it['backorders'] );
				$p->set_backorders( (string) $it['backorders'] );
			}

			if ( array_key_exists( 'sold_individually', $it ) && (bool) $it['sold_individually'] !== (bool) $p->get_sold_individually() ) {
				$chg['sold_individually'] = array( (bool) $p->get_sold_individually(), (bool) $it['sold_individually'] );
				$p->set_sold_individually( (bool) $it['sold_individually'] );
			}

			if ( isset( $it['regular_price'] ) && (string) $it['regular_price'] !== (string) $p->get_regular_price() ) {
				$chg['regular_price'] = array( (string) $p->get_regular_price(), (string) $it['regular_price'] );
				$p->set_regular_price( (string) $it['regular_price'] );
			}

			if ( isset( $it['short_description'] ) && (string) $it['short_description'] !== (string) $p->get_short_description() ) {
				$chg['short_description'] = array( wp_trim_words( (string) $p->get_short_description(), 12 ), wp_trim_words( (string) $it['short_description'], 12 ) );
				$p->set_short_description( (string) $it['short_description'] );
			}

			if ( ! empty( $it['short_description_append'] ) ) {
				$add = trim( (string) $it['short_description_append'] );
				// Idempotent: do not add the same sentence twice on a re-run.
				if ( '' !== $add && false === strpos( (string) $p->get_short_description(), $add ) ) {
					$chg['short_description_appended'] = array( '', $add );
					$p->set_short_description( rtrim( (string) $p->get_short_description() ) . "\n\n" . $add );
				}
			}

			$row = array( 'product_id' => $id, 'title' => $p->get_name(), 'changes' => $chg );

			if ( $dry_run || ! $chg ) {
				$results[] = $row;
				continue;
			}

			$p->save();

			clean_post_cache( $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}

			$after = wc_get_product( $id );
			$bad   = array();

			foreach ( $chg as $k => $pair ) {
				$stored = null;
				switch ( $k ) {
					case 'title':
						$stored = $after->get_name();
						break;
					case 'status':
						$stored = $after->get_status();
						break;
					case 'manage_stock':
						$stored = (bool) $after->get_manage_stock();
						break;
					case 'stock_quantity':
						$stored = (int) $after->get_stock_quantity();
						break;
					case 'stock_status':
						$stored = $after->get_stock_status();
						break;
					case 'backorders':
						$stored = $after->get_backorders();
						break;
					case 'sold_individually':
						$stored = (bool) $after->get_sold_individually();
						break;
					case 'regular_price':
						$stored = (string) $after->get_regular_price();
						break;
					default:
						continue 2;
				}

				if ( $stored !== $pair[1] ) {
					$bad[ $k ] = array( 'asked' => $pair[1], 'stored' => $stored );
				}
			}

			if ( isset( $chg['short_description_appended'] ) && false === strpos( (string) $after->get_short_description(), $chg['short_description_appended'][1] ) ) {
				$bad['short_description_appended'] = 'not found after saving';
			}

			// A stock quantity of 0 flips a product to out of stock by itself, and a
			// positive one flips it back; report where it ended up, since that is the
			// thing a reset is trying to fix.
			$row['stock_status_now'] = $after->get_stock_status();
			$row['verified']         = empty( $bad );
			if ( $bad ) {
				$row['mismatches'] = $bad;
				$verified          = false;
			}

			$results[] = $row;
		}

		return array(
			'dry_run'  => $dry_run,
			'results'  => $results,
			'verified' => $verified,
		);
	}

	/* -----------------------------------------------------------------
	 * fdj/list-order-items
	 * ----------------------------------------------------------------- */

	/**
	 * One row per purchased item, with buyer name and custom details.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_order_items( $input = array() ) {
		$allowed  = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
		$statuses = isset( $input['statuses'] ) && is_array( $input['statuses'] ) ? $input['statuses'] : array( 'processing', 'on-hold', 'completed' );
		$statuses = array_values( array_intersect( array_map( function ( $s ) {
			return preg_replace( '/^wc-/', '', (string) $s );
		}, $statuses ), $allowed ) );

		if ( ! $statuses ) {
			return new WP_Error( 'fdj_orders_bad_status', 'No valid statuses given.' );
		}

		$limit = isset( $input['limit'] ) ? max( 1, min( 500, (int) $input['limit'] ) ) : 200;
		$args  = array(
			'limit'   => $limit,
			'status'  => $statuses,
			'orderby' => 'date',
			'order'   => 'ASC',
			'return'  => 'ids',
		);

		$after  = isset( $input['date_after'] ) ? trim( (string) $input['date_after'] ) : '';
		$before = isset( $input['date_before'] ) ? trim( (string) $input['date_before'] ) : '';

		// A bare date means the whole day.
		if ( '' !== $before && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $before ) ) {
			$before .= ' 23:59:59';
		}

		if ( '' !== $after && '' !== $before ) {
			$args['date_created'] = $after . '...' . $before;
		} elseif ( '' !== $after ) {
			$args['date_created'] = '>=' . $after;
		} elseif ( '' !== $before ) {
			$args['date_created'] = '<=' . $before;
		}

		$cats       = isset( $input['category_slugs'] ) && is_array( $input['category_slugs'] ) ? array_map( 'sanitize_title', $input['category_slugs'] ) : array();
		$only_ids   = isset( $input['product_ids'] ) && is_array( $input['product_ids'] ) ? array_map( 'intval', $input['product_ids'] ) : array();
		$with_email = ! empty( $input['include_email'] );
		$ids        = wc_get_orders( $args );
		$rows       = array();
		$tally      = array();

		foreach ( (array) $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( ! $o ) {
				continue;
			}

			foreach ( $o->get_items() as $item ) {
				$pid = (int) $item->get_product_id();

				if ( $only_ids && ! in_array( $pid, $only_ids, true ) ) {
					continue;
				}

				if ( $cats ) {
					$hit = false;
					foreach ( $cats as $c ) {
						if ( has_term( $c, 'product_cat', $pid ) ) {
							$hit = true;
							break;
						}
					}
					if ( ! $hit ) {
						continue;
					}
				}

				// The details a buyer filled in on the product page. Keys that start
				// with an underscore are WooCommerce's own bookkeeping (stock
				// reductions and so on), not buyer input.
				$details = array();
				foreach ( $item->get_formatted_meta_data( '_', true ) as $m ) {
					$key = trim( wp_strip_all_tags( (string) $m->display_key ) );
					$val = trim( wp_strip_all_tags( (string) $m->display_value ) );
					if ( '' !== $key && '' !== $val ) {
						$details[ $key ] = $val;
					}
				}

				$row = array(
					'order_id'   => (int) $id,
					'date'       => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
					'status'     => $o->get_status(),
					'first_name' => (string) $o->get_billing_first_name(),
					'last_name'  => (string) $o->get_billing_last_name(),
					'product_id' => $pid,
					'product'    => $item->get_name(),
					'quantity'   => (int) $item->get_quantity(),
					'line_total' => (string) $item->get_total(),
					'details'    => $details ? $details : new stdClass(),
				);

				if ( $with_email ) {
					$row['email'] = (string) $o->get_billing_email();
				}

				$rows[] = $row;

				$key = $item->get_name();
				if ( ! isset( $tally[ $key ] ) ) {
					$tally[ $key ] = array( 'product' => $key, 'product_id' => $pid, 'items' => 0, 'quantity' => 0, 'total' => 0.0 );
				}
				++$tally[ $key ]['items'];
				$tally[ $key ]['quantity'] += (int) $item->get_quantity();
				$tally[ $key ]['total']    += (float) $item->get_total();
			}
		}

		foreach ( $tally as $k => $t ) {
			$tally[ $k ]['total'] = round( $t['total'], 2 );
		}

		return array(
			'order_count' => count( (array) $ids ),
			'row_count'   => count( $rows ),
			'truncated'   => count( (array) $ids ) >= $limit,
			'rows'        => $rows,
			'by_product'  => array_values( $tally ),
		);
	}

	/* -----------------------------------------------------------------
	 * fdj/get-order-notes
	 * ----------------------------------------------------------------- */

	/**
	 * Notes on orders, without customer details.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_order_notes( $input = array() ) {
		$ids = array();

		if ( ! empty( $input['order_ids'] ) && is_array( $input['order_ids'] ) ) {
			$ids = array_slice( array_map( 'intval', $input['order_ids'] ), 0, 25 );
		} elseif ( ! empty( $input['status'] ) ) {
			$limit = isset( $input['limit'] ) ? max( 1, min( 25, (int) $input['limit'] ) ) : 10;
			$args  = array(
				'status'  => (string) $input['status'],
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			);
			if ( ! empty( $input['since'] ) ) {
				$args['date_created'] = '>=' . (string) $input['since'];
			}
			$ids = wc_get_orders( $args );
		} else {
			return new WP_Error( 'fdj_orders_none', 'Pass order_ids, or a status (and optionally since).' );
		}

		$orders = array();

		foreach ( $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( ! $o ) {
				$orders[] = array( 'order_id' => (int) $id, 'error' => 'not found' );
				continue;
			}

			$items = array();
			foreach ( $o->get_items() as $item ) {
				$items[] = $item->get_name();
			}

			$notes = array();
			foreach ( (array) wc_get_order_notes( array( 'order_id' => (int) $id ) ) as $n ) {
				$notes[] = array(
					'date'          => $n->date_created ? $n->date_created->date( 'c' ) : '',
					'customer_note' => (bool) $n->customer_note,
					'by'            => $n->added_by,
					'note'          => wp_strip_all_tags( (string) $n->content ),
				);
			}

			$orders[] = array(
				'order_id'       => (int) $id,
				'status'         => $o->get_status(),
				'date'           => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : '',
				'total'          => (string) $o->get_total(),
				'payment_method' => $o->get_payment_method_title(),
				'items'          => $items,
				'notes'          => $notes,
			);
		}

		return array( 'orders' => $orders );
	}

	/* -----------------------------------------------------------------
	 * fdj/annual-reset-report
	 * ----------------------------------------------------------------- */

	/**
	 * Run the checklist.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_annual_reset_report( $input = array() ) {
		$year      = isset( $input['year'] ) ? (int) $input['year'] : (int) wp_date( 'Y' );
		$sections  = array();
		$attention = 0;

		// Products.
		if ( ! empty( $input['category_slugs'] ) && class_exists( 'WooCommerce' ) ) {
			$expected   = isset( $input['expected_stock'] ) ? (int) $input['expected_stock'] : 1;
			$exceptions = isset( $input['stock_exceptions'] ) && is_array( $input['stock_exceptions'] ) ? $input['stock_exceptions'] : array();
			$deadline   = isset( $input['deadline_text'] ) ? (string) $input['deadline_text'] : '';

			$audit = self::execute_audit_products(
				array(
					'category_slugs' => $input['category_slugs'],
					'statuses'       => array( 'publish' ),
					'mentions'       => $deadline,
					'limit'          => 300,
				)
			);

			$issues = array();
			foreach ( $audit['products'] as $p ) {
				$problems = self::product_issues( $p, $expected, $exceptions );
				if ( $problems ) {
					$issues[] = array( 'product_id' => $p['id'], 'title' => $p['title'], 'problems' => $problems );
				}
			}

			$sections['products'] = array(
				'checked'    => $audit['count'],
				'issues'     => $issues,
				'duplicates' => $audit['duplicates'],
			);
			$attention           += count( $issues );
		}

		// Menu.
		if ( isset( $input['menu'] ) && '' !== $input['menu'] ) {
			$menu = self::resolve_menu( $input['menu'] );
			if ( $menu && ! is_wp_error( $menu ) ) {
				$items  = self::menu_items_plain( $menu );
				$issues = self::menu_issues( $items );

				$sections['menu'] = array(
					'menu'    => $menu->name,
					'checked' => count( $items ),
					'issues'  => $issues,
				);
				$attention       += count( $issues );
			} else {
				$sections['menu'] = array( 'error' => 'Menu not found.' );
			}
		}

		// Stale text.
		$stale = isset( $input['stale_text'] ) && is_array( $input['stale_text'] ) ? $input['stale_text'] : array( (string) ( $year - 1 ) );
		global $wpdb;
		$stale_hits = array();

		foreach ( $stale as $needle ) {
			$needle = (string) $needle;
			if ( strlen( $needle ) < 3 ) {
				continue;
			}
			$like = '%' . $wpdb->esc_like( $needle ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','product','tribe_events','post') AND ( post_content LIKE %s OR post_excerpt LIKE %s ) ORDER BY post_type, ID LIMIT 60", $like, $like ) );

			foreach ( (array) $rows as $r ) {
				$stale_hits[] = array( 'text' => $needle, 'post_id' => (int) $r->ID, 'type' => $r->post_type, 'title' => $r->post_title );
			}
		}

		$sections['stale_text'] = array( 'looked_for' => $stale, 'issues' => $stale_hits, 'note' => 'Posts and board minutes that legitimately mention a past year will appear here too. Judge each.' );
		$attention             += count( $stale_hits );

		// Forms.
		$expected_recipient = isset( $input['expected_recipient'] ) ? strtolower( trim( (string) $input['expected_recipient'] ) ) : '';

		if ( ! empty( $input['form_ids'] ) && class_exists( 'GFAPI' ) ) {
			$forms = array();
			$bad   = 0;

			foreach ( array_map( 'intval', (array) $input['form_ids'] ) as $fid ) {
				$form = GFAPI::get_form( $fid );
				if ( ! $form || is_wp_error( $form ) ) {
					$forms[] = array( 'form_id' => $fid, 'error' => 'not found' );
					++$bad;
					continue;
				}

				$active = (int) GFAPI::count_entries( $fid, array( 'status' => 'active' ) );
				$old    = (int) GFAPI::count_entries( $fid, array( 'status' => 'active', 'end_date' => ( $year - 1 ) . '-12-31 23:59:59' ) );
				$trash  = (int) GFAPI::count_entries( $fid, array( 'status' => 'trash' ) );
				$newest = GFAPI::get_entries( $fid, array( 'status' => 'active' ), array( 'key' => 'date_created', 'direction' => 'DESC' ), array( 'offset' => 0, 'page_size' => 1 ) );

				$recips   = array();
				$has_mine = '' === $expected_recipient;
				foreach ( (array) $form['notifications'] as $n ) {
					$to  = isset( $n['to'] ) ? (string) $n['to'] : '';
					$bcc = isset( $n['bcc'] ) ? (string) $n['bcc'] : '';
					if ( '' !== $to || '' !== $bcc ) {
						$recips[] = array( 'notification' => isset( $n['name'] ) ? $n['name'] : '', 'to' => $to, 'bcc' => $bcc );
					}
					if ( '' !== $expected_recipient && false !== stripos( $to . ',' . $bcc, $expected_recipient ) ) {
						$has_mine = true;
					}
				}

				$problems = array();
				if ( $old > 0 ) {
					$problems[] = sprintf( '%d active entries from before %d are still in the list', $old, $year );
				}
				if ( ! $has_mine ) {
					$problems[] = sprintf( 'no notification reaches %s', $expected_recipient );
				}
				if ( empty( $form['is_active'] ) ) {
					$problems[] = 'form is inactive';
				}

				$forms[] = array(
					'form_id'        => $fid,
					'title'          => (string) rgar_fdj( $form, 'title' ),
					'active'         => ! empty( $form['is_active'] ),
					'entries_active' => $active,
					'entries_before_year' => $old,
					'entries_trash'  => $trash,
					'newest_entry'   => ( is_array( $newest ) && $newest ) ? (string) $newest[0]['date_created'] : '',
					'recipients'     => $recips,
					'problems'       => $problems,
				);

				$bad += count( $problems );
			}

			$sections['forms'] = array( 'checked' => count( $forms ), 'forms' => $forms, 'issue_count' => $bad );
			$attention        += $bad;
		}

		// Orders.
		if ( class_exists( 'WooCommerce' ) ) {
			$since  = isset( $input['orders_since'] ) ? (string) $input['orders_since'] : $year . '-09-01';
			$failed = wc_get_orders( array( 'status' => 'failed', 'date_created' => '>=' . $since, 'limit' => 25, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids' ) );

			$recipient_opt = get_option( 'woocommerce_new_order_settings', array() );
			$recipients    = is_array( $recipient_opt ) && isset( $recipient_opt['recipient'] ) ? (string) $recipient_opt['recipient'] : '';
			$problems      = array();

			if ( '' !== $expected_recipient && false === stripos( $recipients, $expected_recipient ) ) {
				$problems[] = sprintf( 'the WooCommerce new-order email does not go to %s', $expected_recipient );
			}

			$sections['orders'] = array(
				'failed_since'        => $since,
				'failed_orders'       => array_map( 'intval', (array) $failed ),
				'failed_count'        => count( (array) $failed ),
				'new_order_recipients' => $recipients,
				'issues'              => $problems,
				'note'                => 'Use fdj/get-order-notes with these IDs to see why each failed. A failed order can be a sponsorship that never happened.',
			);
			$attention         += count( $problems ) + ( count( (array) $failed ) > 0 ? 1 : 0 );
		}

		return array(
			'year'            => $year,
			'needs_attention' => $attention,
			'sections'        => $sections,
		);
	}
}

/* ---------------------------------------------------------------------
 * Tiny global helpers.
 *
 * Gravity Forms hands back fields as objects and everything else as arrays,
 * and the pure logic above must accept both so it can be tested without
 * Gravity Forms. These are prefixed so they cannot collide with another
 * plugin's helpers.
 * ------------------------------------------------------------------- */

if ( ! function_exists( 'fdj_prop' ) ) {
	/**
	 * Read a property from an object or an array.
	 *
	 * @param mixed  $thing Object or array.
	 * @param string $key   Property.
	 * @return mixed
	 */
	function fdj_prop( $thing, $key ) {
		if ( is_array( $thing ) ) {
			return isset( $thing[ $key ] ) ? $thing[ $key ] : null;
		}

		if ( is_object( $thing ) ) {
			return isset( $thing->$key ) ? $thing->$key : null;
		}

		return null;
	}
}

if ( ! function_exists( 'fdj_set_prop' ) ) {
	/**
	 * Set a property on an object or an array, returning the changed value.
	 *
	 * Objects are cloned first so a dry run can never write through a shared
	 * reference into the form it is only meant to inspect.
	 *
	 * @param mixed  $thing Object or array.
	 * @param string $key   Property.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function fdj_set_prop( $thing, $key, $value ) {
		if ( is_array( $thing ) ) {
			$thing[ $key ] = $value;
			return $thing;
		}

		if ( is_object( $thing ) ) {
			$thing = clone $thing;
			$thing->$key = $value;
			return $thing;
		}

		return $thing;
	}
}

if ( ! function_exists( 'rgar_fdj' ) ) {
	/**
	 * Safe array read, like Gravity Forms' rgar() but not dependent on it.
	 *
	 * @param mixed  $array Array.
	 * @param string $key   Key.
	 * @return mixed
	 */
	function rgar_fdj( $array, $key ) {
		return ( is_array( $array ) && isset( $array[ $key ] ) ) ? $array[ $key ] : '';
	}
}

if ( ! function_exists( 'wp_json_encode_fdj' ) ) {
	/**
	 * JSON encode without needing WordPress loaded.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	function wp_json_encode_fdj( $value ) {
		return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $value ) : (string) json_encode( $value );
	}
}

if ( ! function_exists( 'wp_parse_url_path' ) ) {
	/**
	 * Path part of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function wp_parse_url_path( $url ) {
		$p = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH );
		return is_string( $p ) ? $p : '';
	}
}

if ( ! function_exists( 'untrailingslashit_fdj' ) ) {
	/**
	 * Strip a trailing slash and any #fragment, for comparing links.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function untrailingslashit_fdj( $url ) {
		$url = preg_replace( '/#.*$/', '', (string) $url );
		return strtolower( rtrim( $url, '/' ) );
	}
}
