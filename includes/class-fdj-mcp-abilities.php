<?php
/**
 * Ability registration.
 *
 * Abilities are declared once in get_definitions() so the settings screen can
 * list them without duplicating knowledge, and only enabled ones register.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers FDJ abilities with the WordPress Abilities API.
 */
class FDJ_MCP_Abilities {

	/** Hard ceiling on excerpts returned by a single search, to bound response size. */
	const MAX_MATCHES = 50;

	/** Attribute keys checked for a literal (non-token) style override on a Fusion Builder element. */
	const STYLE_ATTRIBUTE_KEYS = array( 'font_size', 'text_color', 'color', 'background_color', 'text_transform', 'letter_spacing', 'line_height', 'border_color' );

	/** Wrapper tags that can nest inside themselves; fdj/update-fusion-element refuses to remove these. */
	const UNSAFE_REMOVAL_TAGS = array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_row_inner', 'fusion_builder_column' );

	/**
	 * Option name prefixes fdj/list-options and fdj/get-option are allowed to touch.
	 *
	 * Theme Options, widgets, and customizer settings live in wp_options, outside
	 * every ability above this line. Rather than hardcode a library of Avada's own
	 * setting names, these two abilities read wp_options directly and stay scoped
	 * to this allowlist, so a generic "read an option" capability can never become
	 * a way to read a stored credential or API key.
	 */
	const SAFE_OPTION_PREFIXES = array( 'widget_', 'sidebars_widgets', 'fusion_options', 'theme_mods_', 'avada_' );

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Ability definitions.
	 *
	 * `is_write` is used by the settings UI to flag which toggles carry risk.
	 * It is not part of the Abilities API.
	 *
	 * @return array<string, array>
	 */
	public static function get_definitions() {

		// Reused by every write ability that edits an existing post.
		$expected_modified = array(
			'type'        => 'string',
			'description' => 'Optional concurrency guard. Pass the post_modified value you last read. If the post has changed since, the write is refused instead of silently overwriting someone else\'s edit.',
		);

		return array(

			/* ---------------------------------------------------------- READ */

			'fdj/list-posts' => array(
				'is_write'            => false,
				'label'               => 'List Posts or Pages',
				'description'         => 'List or search WordPress posts and pages by type, status, and search term. Useful for finding the right post_id before reading or editing.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type to search, e.g. "page" or "post". Defaults to "page".',
							'default'     => 'page',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Post status to filter by. Defaults to "any".',
							'default'     => 'any',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Optional search term matched against the title.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Max results to return. Capped at 100.',
							'default'     => 20,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'post_id'  => array( 'type' => 'integer' ),
							'title'    => array( 'type' => 'string' ),
							'status'   => array( 'type' => 'string' ),
							'view_url' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_posts' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			'fdj/search-content' => array(
				'is_write'            => false,
				'label'               => 'Search Content Site-Wide',
				'description'         => 'Find which posts or pages contain a literal string, anywhere in their content. Returns matching posts with an occurrence count, not the content itself. Use this before a site-wide replace to see the blast radius.',
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
							'description' => 'Literal string to find. Not a regular expression, and not word-split like WordPress search.',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Restrict to one post type, or "any". Defaults to "any".',
							'default'     => 'any',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Restrict to one post status, or "any". Defaults to "any".',
							'default'     => 'any',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Max posts to return. Capped at 100.',
							'default'     => 25,
						),
					),
					'required'   => array( 'search' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_posts'       => array( 'type' => 'integer' ),
						'total_occurrences' => array( 'type' => 'integer' ),
						'results'           => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'post_id'     => array( 'type' => 'integer' ),
									'title'       => array( 'type' => 'string' ),
									'post_type'   => array( 'type' => 'string' ),
									'status'      => array( 'type' => 'string' ),
									'occurrences' => array( 'type' => 'integer' ),
									'edit_url'    => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_search_content' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			'fdj/get-post' => array(
				'is_write'            => false,
				'label'               => 'Get Post or Page',
				'description'         => 'Fetch a post or page by ID. Pass "search" to get only the matching regions with surrounding context instead of the entire body, which matters on large page-builder pages where the full content can be enormous. Always returns "modified", which you can pass back as expected_modified on a write.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'       => array(
							'type'        => 'integer',
							'description' => 'The post or page ID to fetch.',
						),
						'search'        => array(
							'type'        => 'string',
							'description' => 'Optional literal string. When given, returns matching excerpts instead of the full content.',
						),
						'context_chars' => array(
							'type'        => 'integer',
							'description' => 'Characters of context on each side of a match. Defaults to 400, capped at 4000.',
							'default'     => 400,
						),
						'include_content' => array(
							'type'        => 'boolean',
							'description' => 'Force inclusion of the full content even when searching. Defaults to false when "search" is set, true otherwise.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'post_type'   => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'content'     => array( 'type' => 'string' ),
						'content_length' => array( 'type' => 'integer' ),
						'match_count' => array( 'type' => 'integer' ),
						'matches'     => array( 'type' => 'array' ),
						'status'      => array( 'type' => 'string' ),
						'edit_url'    => array( 'type' => 'string' ),
						'view_url'    => array( 'type' => 'string' ),
						'modified'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_post' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/list-revisions' => array(
				'is_write'            => false,
				'label'               => 'List Revisions',
				'description'         => 'List stored revisions for a post or page, newest first. WordPress records one automatically on every content change, so this is the undo history for any edit made through these abilities.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'The post or page ID.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Max revisions to return. Defaults to 20, capped at 100.',
							'default'     => 20,
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'revision_id' => array( 'type' => 'integer' ),
							'date'        => array( 'type' => 'string' ),
							'author'      => array( 'type' => 'string' ),
							'is_autosave' => array( 'type' => 'boolean' ),
							'title'       => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_revisions' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/list-fusion-builder-elements' => array(
				'is_write'            => false,
				'requires'            => 'avada',
				'label'               => 'List Fusion Builder Elements',
				'description'         => 'Parse a page\'s Fusion Builder shortcode tree into a flat list of elements: tag, position, a short text preview, and which style attributes are literal overrides versus inherited from the site\'s global Avada theme settings (an inherited value reads as a var(--awb-...) token; an override is a literal value like "80px"). Flags fusion_global references separately, since those are reusable blocks stored as their own post and need a separate fdj/get-post call to reach. Use this before editing one element so a write can target it precisely instead of touching the whole page.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => 'The post or page ID to parse.',
						),
						'element_type' => array(
							'type'        => 'string',
							'description' => 'Optional. Restrict to one shortcode tag, e.g. "fusion_title" or "fusion_counter_box". Leave empty to list every Fusion Builder element on the page.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'element_type'      => array( 'type' => 'string' ),
							'occurrence'        => array(
								'type'        => 'integer',
								'description' => '0-based index of this element among others sharing the same element_type on this page.',
							),
							'text_preview'      => array( 'type' => 'string' ),
							'is_global_ref'     => array( 'type' => 'boolean' ),
							'global_id'         => array( 'type' => 'integer' ),
							'literal_overrides' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'attributes'        => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_fusion_builder_elements' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/list-options' => array(
				'is_write'            => false,
				'label'               => 'List Site Options by Name',
				'description'         => 'Find WordPress option names matching a search term, restricted to theme and widget configuration (widget_*, sidebars_widgets, fusion_options, theme_mods_*, avada_*). Use this to locate where a global setting actually lives, such as a footer widget or an Avada Theme Options field, before reading it with fdj/get-option. Cannot see unrelated options like credentials or API keys; those are out of scope by design, not filtered after the fact.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Substring to match against option_name.',
						),
					),
					'required'   => array( 'search' ),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'option_name'  => array( 'type' => 'string' ),
							'value_length' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_options' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
			),

			'fdj/get-option' => array(
				'is_write'            => false,
				'label'               => 'Get Site Option',
				'description'         => 'Read one WordPress option by its exact name, restricted to theme and widget configuration (widget_*, sidebars_widgets, fusion_options, theme_mods_*, avada_*). This is how Avada Theme Options and classic footer/sidebar widgets are read, since both live in wp_options rather than the post table and nothing above this ability can see them. Run fdj/list-options first if the exact name is not already known.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'option_name' => array(
							'type'        => 'string',
							'description' => 'Exact option_name from wp_options, e.g. "fusion_options" or "sidebars_widgets".',
						),
					),
					'required'   => array( 'option_name' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'option_name' => array( 'type' => 'string' ),
						'value'       => array( 'description' => 'The option value. Arrays come through as structured data, not a serialized string.' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_option' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
			),

			'fdj/list-media' => array(
				'is_write'            => false,
				'label'               => 'List Media',
				'description'         => 'Search or list media library attachments by title/caption text, MIME type, and date. Use this before fdj/upload-media to check whether an image already exists rather than uploading a duplicate, or to find an attachment_id to reuse in fdj/update-fusion-element. The search also tends to catch filenames, since WordPress derives the initial title from the uploaded filename, but a file whose title was changed afterward, or that was renamed only on disk, will not match by filename alone.',
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
							'description' => 'Optional search term matched against title and caption.',
						),
						'mime_type' => array(
							'type'        => 'string',
							'description' => 'Optional MIME type filter. A partial type like "image" matches every image/* subtype; a full type like "application/pdf" matches only that.',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Max results to return. Defaults to 20, capped at 100.',
							'default'     => 20,
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number, 1-based. Defaults to 1.',
							'default'     => 1,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'attachment_id' => array( 'type' => 'integer' ),
							'title'         => array( 'type' => 'string' ),
							'filename'      => array( 'type' => 'string' ),
							'mime_type'     => array( 'type' => 'string' ),
							'url'           => array( 'type' => 'string' ),
							'alt_text'      => array( 'type' => 'string' ),
							'width'         => array( 'type' => 'integer' ),
							'height'        => array( 'type' => 'integer' ),
							'file_size'     => array( 'type' => 'integer' ),
							'date'          => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_media' ),
				'permission_callback' => array( __CLASS__, 'can_upload_files' ),
			),

			'fdj/get-media' => array(
				'is_write'            => false,
				'label'               => 'Get Media',
				'description'         => 'Fetch one media library attachment by ID: full metadata, alt text, caption, description, the post it is attached to if any, and every registered image size with its own URL and dimensions.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => 'The attachment ID.',
						),
					),
					'required'   => array( 'attachment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id'   => array( 'type' => 'integer' ),
						'title'           => array( 'type' => 'string' ),
						'filename'        => array( 'type' => 'string' ),
						'mime_type'       => array( 'type' => 'string' ),
						'url'             => array( 'type' => 'string' ),
						'alt_text'        => array( 'type' => 'string' ),
						'caption'         => array( 'type' => 'string' ),
						'description'     => array( 'type' => 'string' ),
						'parent_post_id'  => array( 'type' => 'integer' ),
						'file_size'       => array( 'type' => 'integer' ),
						'date'            => array( 'type' => 'string' ),
						'sizes'           => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_media' ),
				'permission_callback' => array( __CLASS__, 'can_upload_files' ),
			),

			'fdj/get-post-meta' => array(
				'is_write'            => false,
				'label'               => 'Get Post Meta',
				'description'         => 'Read every custom field stored on one post, page, or WooCommerce product (products are posts under the hood). WooCommerce\'s own native abilities expose only a fixed catalog field set, name/price/stock/status/etc. with no custom-fields escape hatch, so this is the only way to see something a plugin bolted on as post meta, e.g. a "Gravity Forms Product Add-Ons for WooCommerce"-style link between a product and a form. Pass "keys" to fetch specific meta keys instead of everything.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The post, page, or product ID.',
						),
						'keys'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Optional. Fetch only these meta keys instead of every key on the post.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'meta'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'key'   => array( 'type' => 'string' ),
									'value' => array( 'description' => 'A single value, or an array when this key is genuinely stored with more than one value.' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_post_meta' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			/* --------------------------------------------------------- WRITE */

			'fdj/replace-in-post' => array(
				'is_write'            => true,
				'label'               => 'Replace Text in Post or Page',
				'description'         => 'Find and replace a literal string inside one post or page, leaving everything else untouched. Strongly preferred over rewriting whole content: it is cheaper, and it cannot damage the parts of the page it did not match. Run with dry_run first to see what would change.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => 'The post or page ID to edit.',
						),
						'search'            => array(
							'type'        => 'string',
							'description' => 'Literal string to find. Not a regular expression.',
						),
						'replace'           => array(
							'type'        => 'string',
							'description' => 'Replacement string. Pass an empty string to delete the matched text.',
						),
						'expect_count'      => array(
							'type'        => 'integer',
							'description' => 'Safety guard. If given and the actual number of matches differs, nothing is written and the real count is reported. Use this whenever you believe you know how many occurrences there are.',
						),
						'dry_run'           => array(
							'type'        => 'boolean',
							'description' => 'Report what would change without saving. Defaults to false.',
							'default'     => false,
						),
						'limit'             => array(
							'type'        => 'integer',
							'description' => 'Replace at most this many occurrences, from the start. Omit to replace all.',
						),
						'expected_modified' => $expected_modified,
					),
					'required'   => array( 'post_id', 'search', 'replace' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer' ),
						'found'        => array( 'type' => 'integer' ),
						'replaced'     => array( 'type' => 'integer' ),
						'dry_run'      => array( 'type' => 'boolean' ),
						'previews'     => array( 'type' => 'array' ),
						'modified'     => array( 'type' => 'string' ),
						'view_url'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_replace_in_post' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/update-post-content' => array(
				'is_write'            => true,
				'label'               => 'Update Post or Page Content',
				'description'         => 'Replace the entire content of an existing post or page, and optionally the title and status. Prefer fdj/replace-in-post for targeted edits: this overwrites everything, including parts you did not intend to touch.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => 'The post or page ID to update.',
						),
						'content'           => array(
							'type'        => 'string',
							'description' => 'New post_content. Replaces the existing content entirely.',
						),
						'title'             => array(
							'type'        => 'string',
							'description' => 'Optional new post title.',
						),
						'status'            => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
							'description' => 'Optional new post status.',
						),
						'expected_modified' => $expected_modified,
					),
					'required'   => array( 'post_id', 'content' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'status'   => array( 'type' => 'string' ),
						'modified' => array( 'type' => 'string' ),
						'view_url' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_post' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/create-post' => array(
				'is_write'            => true,
				'label'               => 'Create Post or Page',
				'description'         => 'Create a new WordPress post or page with the given title, content, and type. Content can include raw page builder shortcodes.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'post_type' => array(
							'type'    => 'string',
							'default' => 'page',
						),
						'status'    => array(
							'type'    => 'string',
							'enum'    => array( 'draft', 'pending', 'publish', 'private' ),
							'default' => 'draft',
						),
					),
					'required'   => array( 'title', 'content' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'status'   => array( 'type' => 'string' ),
						'edit_url' => array( 'type' => 'string' ),
						'view_url' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_create_post' ),
				'permission_callback' => array( __CLASS__, 'can_create_post' ),
			),

			'fdj/restore-revision' => array(
				'is_write'            => true,
				'label'               => 'Restore Revision',
				'description'         => 'Roll a post or page back to a stored revision. This is the undo for any edit made through these abilities. Use fdj/list-revisions first to pick the revision_id.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array(
							'type'        => 'integer',
							'description' => 'The post or page ID to roll back.',
						),
						'revision_id' => array(
							'type'        => 'integer',
							'description' => 'The revision to restore. Must belong to this post.',
						),
					),
					'required'   => array( 'post_id', 'revision_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'restored' => array( 'type' => 'integer' ),
						'modified' => array( 'type' => 'string' ),
						'view_url' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_restore_revision' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/update-fusion-element' => array(
				'is_write'            => true,
				'requires'            => 'avada',
				'label'               => 'Update or Remove a Fusion Builder Element',
				'description'         => 'Change one Fusion Builder element\'s attributes (font size, color, a carousel\'s arrow toggle, anything that is a shortcode attribute), or remove the element entirely. Locate it first with fdj/list-fusion-builder-elements: element_type and occurrence together identify the same element in both abilities. Set an attribute to null (not an empty string) to clear it back to Avada\'s own default instead of pinning it to a literal value, prefer this over guessing a value that merely looks right. Refuses to remove layout wrapper tags (container, row, column), since those can nest inside themselves and a naive removal could take a whole section with it. Run with dry_run first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => 'The post or page ID.',
						),
						'element_type'      => array(
							'type'        => 'string',
							'description' => 'The shortcode tag, e.g. "fusion_title".',
						),
						'occurrence'        => array(
							'type'        => 'integer',
							'description' => '0-based index among elements of this type, as reported by fdj/list-fusion-builder-elements.',
							'default'     => 0,
						),
						'attributes'        => array(
							'type'        => 'object',
							'description' => 'Attribute key/value pairs to set on the element. Merged over its existing attributes; keys not listed here are left untouched. Use null as a value to remove that key entirely, falling back to Avada\'s default, rather than writing a literal value that merely matches the default by coincidence. Omit if remove is true.',
						),
						'remove'            => array(
							'type'        => 'boolean',
							'description' => 'If true, delete the whole element instead of updating attributes.',
							'default'     => false,
						),
						'dry_run'           => array(
							'type'        => 'boolean',
							'description' => 'Preview the change without saving.',
							'default'     => false,
						),
						'expected_modified' => array(
							'type'        => 'string',
							'description' => 'Optional concurrency guard. Pass the post_modified value you last read. If the post has changed since, the write is refused instead of silently overwriting someone else\'s edit.',
						),
					),
					'required'   => array( 'post_id', 'element_type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer' ),
						'element_type' => array( 'type' => 'string' ),
						'occurrence'   => array( 'type' => 'integer' ),
						'action'       => array( 'type' => 'string' ),
						'dry_run'      => array( 'type' => 'boolean' ),
						'before'       => array( 'type' => 'string' ),
						'after'        => array( 'type' => 'string' ),
						'modified'     => array( 'type' => 'string' ),
						'view_url'     => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_fusion_element' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/upload-media' => array(
				'is_write'            => true,
				'label'               => 'Upload Media',
				'description'         => 'Download a file from a URL into the WordPress media library and return its attachment ID and URL. Use the attachment ID as the image_id (or equivalent) attribute in an fdj/update-fusion-element call to actually place it on a page.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'source_url' => array(
							'type'        => 'string',
							'description' => 'Publicly reachable URL of the image or file to import.',
						),
						'title'      => array(
							'type'        => 'string',
							'description' => 'Media library title. Defaults to the filename from the URL.',
						),
						'alt_text'   => array(
							'type'        => 'string',
							'description' => 'Alt text for the image.',
						),
					),
					'required'   => array( 'source_url' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
						'width'         => array( 'type' => 'integer' ),
						'height'        => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_upload_media' ),
				'permission_callback' => array( __CLASS__, 'can_upload_files' ),
			),

			'fdj/delete-post' => array(
				'is_write'            => true,
				'label'               => 'Delete Post or Page',
				'description'         => 'Move a post or page to the trash. Trashed content is recoverable from wp-admin like any normal WordPress delete, so this is not the destructive kind of destructive. Pass force to skip the trash and delete permanently; that cannot be undone through these abilities.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => 'The post or page ID to delete.',
						),
						'force'             => array(
							'type'        => 'boolean',
							'description' => 'Skip the trash and delete permanently. Cannot be undone through these abilities. Defaults to false (trash, recoverable).',
							'default'     => false,
						),
						'expected_modified' => array(
							'type'        => 'string',
							'description' => 'Optional concurrency guard, same as on other writes.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'action'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_delete_post' ),
				'permission_callback' => array( __CLASS__, 'can_delete_post' ),
			),

			'fdj/replace-in-option' => array(
				'is_write'            => true,
				'label'               => 'Replace Text Inside a Site Option',
				'description'         => 'Find and replace a literal string inside one string field of a WordPress option, restricted to the same safe prefixes as fdj/get-option (widget_*, sidebars_widgets, fusion_options, theme_mods_*, avada_*). Options like widget_text or fusion_options are nested arrays, not a single string, so "path" says which field to edit, e.g. ["277991649","text"] to reach one footer text widget\'s body, or ["footer_text"] for an Avada Theme Options field. Only that one field changes; everything else in the option is written back exactly as read. Run with dry_run first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'option_name'  => array(
							'type'        => 'string',
							'description' => 'Exact option_name from wp_options.',
						),
						'path'         => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Keys navigating into the option\'s array to the one string field to edit. Get this from fdj/get-option first; do not guess it.',
						),
						'search'       => array(
							'type'        => 'string',
							'description' => 'Literal string to find within that field. Not a regular expression.',
						),
						'replace'      => array(
							'type'        => 'string',
							'description' => 'Replacement string.',
						),
						'expect_count' => array(
							'type'        => 'integer',
							'description' => 'Safety guard. If given and the actual number of matches differs, nothing is written and the real count is reported.',
						),
						'dry_run'      => array(
							'type'        => 'boolean',
							'description' => 'Preview the change without saving. Defaults to false.',
							'default'     => false,
						),
					),
					'required'   => array( 'option_name', 'path', 'search', 'replace' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'option_name' => array( 'type' => 'string' ),
						'path'        => array( 'type' => 'array' ),
						'found'       => array( 'type' => 'integer' ),
						'replaced'    => array( 'type' => 'integer' ),
						'dry_run'     => array( 'type' => 'boolean' ),
						'before'      => array( 'type' => 'string' ),
						'after'       => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_replace_in_option' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
			),

			'fdj/get-theme-info' => array(
				'is_write'            => false,
				'label'               => 'Get Theme Info',
				'description'         => 'Read the active theme and the extension points a build needs to target: parent/child theme names and versions, every registered nav menu location with the menu currently assigned to it, every registered sidebar, and which page builder is active. On a from-scratch build this is the first call to make, because menu location slugs and sidebar IDs are theme-specific and guessing them wastes a write.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet'     => array( 'type' => 'string' ),
						'template'       => array( 'type' => 'string' ),
						'name'           => array( 'type' => 'string' ),
						'version'        => array( 'type' => 'string' ),
						'is_child_theme' => array( 'type' => 'boolean' ),
						'parent'         => array( 'type' => 'string' ),
						'menu_locations' => array( 'type' => 'array' ),
						'sidebars'       => array( 'type' => 'array' ),
						'builders'       => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_theme_info' ),
				'permission_callback' => array( __CLASS__, 'can_edit_theme_options' ),
			),

			'fdj/set-option-value' => array(
				'is_write'            => true,
				'label'               => 'Set a Value Inside a Site Option',
				'description'         => 'Write one value at a given path inside an allowlisted option (widget_*, sidebars_widgets, fusion_options, theme_mods_*, avada_*), creating any missing keys along the way. This is the companion to fdj/replace-in-option, which can only rewrite a string that is already there: on a fresh site Avada Theme Options is an almost empty array, so nearly every global setting has to be created rather than found and replaced. Pass an empty path to replace the whole option. Use expect_current to refuse the write if the value is not what you last read. Run with dry_run first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'option_name'     => array(
							'type'        => 'string',
							'description' => 'Exact option_name from wp_options.',
						),
						'path'            => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Keys navigating into the option to the value being set, e.g. ["primary_color"] for one Avada Theme Options field. Missing keys are created. An empty array replaces the entire option value.',
						),
						'value'           => array(
							'description' => 'The value to write. Any JSON type: string, number, boolean, array or object. Objects and arrays are stored as PHP arrays, which is how WordPress stores nested option data.',
						),
						'expect_current'  => array(
							'description' => 'Optional concurrency guard. If given, the write is refused unless the value currently at that path is identical. Pass null to assert the key does not exist yet.',
						),
						'create_missing'  => array(
							'type'        => 'boolean',
							'description' => 'Create intermediate keys that do not exist. Defaults to true. Set false to refuse rather than create, when you expect the path to already be there.',
							'default'     => true,
						),
						'create_option'   => array(
							'type'        => 'boolean',
							'description' => 'Create the option itself if no such option row exists. Defaults to false, so a typo in option_name fails loudly instead of quietly creating a second, wrong option.',
							'default'     => false,
						),
						'dry_run'         => array(
							'type'        => 'boolean',
							'description' => 'Preview the change without saving. Defaults to false.',
							'default'     => false,
						),
					),
					'required'   => array( 'option_name', 'path', 'value' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'option_name' => array( 'type' => 'string' ),
						'path'        => array( 'type' => 'array' ),
						'created'     => array( 'type' => 'boolean' ),
						'dry_run'     => array( 'type' => 'boolean' ),
						'before'      => array( 'description' => 'Value previously at that path, or null if it did not exist.' ),
						'after'       => array( 'description' => 'Value now at that path.' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_set_option_value' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
			),

			'fdj/set-core-setting' => array(
				'is_write'            => true,
				'label'               => 'Set a Core Site Setting',
				'description'         => 'Write one of a small fixed list of core WordPress settings that live outside the theme/widget option prefixes: site title and tagline, the static front page and posts page, permalink structure, date/time formats, timezone, posts per page, and search engine visibility. The list is a hardcoded allowlist, not a prefix rule, so this ability can never reach an unrelated option. Setting a static front page is the one step a from-scratch build cannot do any other way.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'setting' => array(
							'type'        => 'string',
							'enum'        => array( 'blogname', 'blogdescription', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'date_format', 'time_format', 'start_of_week', 'timezone_string', 'permalink_structure', 'blog_public', 'site_icon' ),
							'description' => 'Which setting to write.',
						),
						'value'   => array(
							'description' => 'The new value. show_on_front takes "page" or "posts". page_on_front, page_for_posts and site_icon take a post/attachment ID. blog_public takes 1 (visible) or 0 (discourage indexing).',
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => 'Preview the change without saving. Defaults to false.',
							'default'     => false,
						),
					),
					'required'   => array( 'setting', 'value' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'setting' => array( 'type' => 'string' ),
						'before'  => array(),
						'after'   => array(),
						'dry_run' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_set_core_setting' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
			),

			'fdj/update-post-meta' => array(
				'is_write'            => true,
				'label'               => 'Update Post Meta',
				'description'         => 'Write or delete custom fields on one post, page, or layout section. This is the write counterpart to fdj/get-post-meta. Page builders keep per-page settings here rather than in post_content: Avada stores page background colour, header transparency and title-bar visibility as pyre_* meta, and a Layout\'s slot assignments as its own meta, so a page can look wrong on a site where every shortcode is already correct. Also the only way to set a page template (_wp_page_template) or a featured image (_thumbnail_id). Pass null as a value to delete that key. Run with dry_run first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'           => array(
							'type'        => 'integer',
							'description' => 'The post, page, or custom post type ID to write meta on.',
						),
						'meta'              => array(
							'type'                 => 'object',
							'description'          => 'Map of meta_key to value. A null value deletes that key. Values may be strings, numbers, booleans, arrays or objects; arrays and objects are stored serialized, exactly as WordPress does natively.',
							'additionalProperties' => true,
						),
						'expected_modified' => $expected_modified,
						'dry_run'           => array(
							'type'        => 'boolean',
							'description' => 'Preview the change without saving. Defaults to false.',
							'default'     => false,
						),
					),
					'required'   => array( 'post_id', 'meta' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'changes' => array( 'type' => 'array' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_post_meta' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/set-post-terms' => array(
				'is_write'            => true,
				'label'               => 'Set Post Terms',
				'description'         => 'Assign taxonomy terms to a post. Needed beyond ordinary categories and tags because builders type their reusable parts with a private taxonomy: an Avada Layout Section is only recognised as a header or a footer because of its fusion_tb_category term, so a section created with the right content but no term is invisible to the theme. Replaces the post\'s terms in that taxonomy unless append is true.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array(
							'type'        => 'integer',
							'description' => 'The post ID to assign terms to.',
						),
						'taxonomy'       => array(
							'type'        => 'string',
							'description' => 'Taxonomy name, e.g. "category", "post_tag", or "fusion_tb_category".',
						),
						'terms'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Term slugs or names to assign. An empty array clears the post\'s terms in this taxonomy.',
						),
						'append'         => array(
							'type'        => 'boolean',
							'description' => 'Add to the post\'s existing terms instead of replacing them. Defaults to false.',
							'default'     => false,
						),
						'create_missing' => array(
							'type'        => 'boolean',
							'description' => 'Create any term that does not exist yet. Defaults to false, so a typo fails loudly instead of silently creating a near-duplicate term.',
							'default'     => false,
						),
					),
					'required'   => array( 'post_id', 'taxonomy', 'terms' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'taxonomy' => array( 'type' => 'string' ),
						'before'   => array( 'type' => 'array' ),
						'after'    => array( 'type' => 'array' ),
						'created'  => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_set_post_terms' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/get-post-terms' => array(
				'is_write'            => false,
				'label'               => 'Get Post Terms',
				'description'         => 'Read the taxonomy terms assigned to one post, with each term\'s exact term_id, name and slug. This is the missing read half of fdj/set-post-terms, which until now wrote terms blind: a page builder types its reusable parts with a private taxonomy, so when an Avada Layout Section is built correctly and still does not render, the term is the first thing to suspect and there was previously no way to look at it. Omit "taxonomy" to get every taxonomy that applies to the post.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'The post, page or layout section to read terms from.',
						),
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'Limit to one taxonomy, e.g. "fusion_tb_category". Omit to return every taxonomy registered for this post type.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'post_type'  => array( 'type' => 'string' ),
						'taxonomies' => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_post_terms' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			'fdj/list-terms' => array(
				'is_write'            => false,
				'label'               => 'List Taxonomy Terms',
				'description'         => 'List the terms in one taxonomy with their term_id, name, slug, parent and post count. Use it to confirm a slug before writing it, and to see the real shape of a term tree: WordPress derives a slug from a name and will silently suffix it when the slug is already taken, so the term you think you created as "footer" can actually be "footer-2" and match nothing. Also the way to enumerate portfolio or gallery filter categories before building an element that depends on them.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'   => array(
							'type'        => 'string',
							'description' => 'Taxonomy name, e.g. "category", "fusion_tb_category", "portfolio_category".',
						),
						'search'     => array(
							'type'        => 'string',
							'description' => 'Optional term name or slug fragment to filter by.',
						),
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => 'Skip terms with no posts. Defaults to false, because a term created for a build is legitimately empty until content is assigned to it.',
							'default'     => false,
						),
						'per_page'   => array(
							'type'        => 'integer',
							'description' => 'Max terms to return. Capped at 200.',
							'default'     => 100,
						),
					),
					'required'   => array( 'taxonomy' ),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'term_id' => array( 'type' => 'integer' ),
							'name'    => array( 'type' => 'string' ),
							'slug'    => array( 'type' => 'string' ),
							'parent'  => array( 'type' => 'integer' ),
							'count'   => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_terms' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			'fdj/list-post-types' => array(
				'is_write'            => false,
				'label'               => 'List Registered Post Types',
				'description'         => 'List every post type registered on this site: its exact name, label, whether it is public or admin-only, which taxonomies apply to it, and how many posts it holds by status. Page builders keep their parts in private post types whose names cannot be guessed from the admin UI, and pointing another ability at a name that does not exist returns an empty list that is indistinguishable from a real type with no posts. That false negative is worth avoiding: it reads as proof that something is absent when it only means you spelled it wrong. Call this first whenever a post type name is an assumption rather than a fact.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'      => array(
							'type'        => 'string',
							'description' => 'Optional fragment matched against the post type name and label, e.g. "fusion" or "layout".',
						),
						'public_only' => array(
							'type'        => 'boolean',
							'description' => 'Return only public post types. Defaults to false, since the interesting ones on a builder site are private.',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'       => array( 'type' => 'string' ),
							'label'      => array( 'type' => 'string' ),
							'public'     => array( 'type' => 'boolean' ),
							'show_ui'    => array( 'type' => 'boolean' ),
							'taxonomies' => array( 'type' => 'array' ),
							'counts'     => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_post_types' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			'fdj/upload-media-data' => array(
				'is_write'            => true,
				'label'               => 'Upload Media from Data',
				'description'         => 'Create a media library attachment from base64-encoded file content. fdj/upload-media can only sideload a publicly reachable URL, which is no help for the usual case at the start of a build, where the approved photography sits on the builder\'s own machine or inside a design file and has never been published anywhere. Send web-optimised files: this travels through the request body, so resize and compress before encoding rather than sending camera originals.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'filename' => array(
							'type'        => 'string',
							'description' => 'Filename including extension, e.g. "hero-arranging-peonies.jpg". The extension decides the MIME type and must be one WordPress allows for upload.',
						),
						'content'  => array(
							'type'        => 'string',
							'description' => 'Base64-encoded file content. Plain base64, not a data: URI.',
						),
						'title'    => array(
							'type'        => 'string',
							'description' => 'Media library title. Defaults to the filename without its extension.',
						),
						'alt_text' => array(
							'type'        => 'string',
							'description' => 'Alt text for the image. Worth setting here rather than later; it is the accessible name of every image on the page.',
						),
						'caption'  => array(
							'type'        => 'string',
							'description' => 'Attachment caption.',
						),
					),
					'required'   => array( 'filename', 'content' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
						'width'         => array( 'type' => 'integer' ),
						'height'        => array( 'type' => 'integer' ),
						'bytes'         => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_upload_media_data' ),
				'permission_callback' => array( __CLASS__, 'can_upload_files' ),
			),

			'fdj/manage-menu' => array(
				'is_write'            => true,
				'label'               => 'Manage a Navigation Menu',
				'description'         => 'Create a nav menu, set its items, and assign it to a theme location, in one call. Nothing else in this plugin can reach menus: they are terms with ordered posts hanging off them, not options or post content, so a header built entirely correctly still renders with no navigation until this runs. Items are given as a flat list; use "parent" to make a dropdown, pointing at the 1-based position of the parent item in the same list. Setting items replaces every existing item in the menu. Run with dry_run first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu_name'         => array(
							'type'        => 'string',
							'description' => 'Menu name, e.g. "Main Navigation". Matched by name or slug.',
						),
						'create_if_missing' => array(
							'type'        => 'boolean',
							'description' => 'Create the menu if no menu of that name exists. Defaults to true.',
							'default'     => true,
						),
						'items'             => array(
							'type'        => 'array',
							'description' => 'The menu\'s items, in order. Omit to leave existing items untouched and only assign a location. An empty array empties the menu.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'title'   => array(
										'type'        => 'string',
										'description' => 'Link label as it appears in the menu.',
									),
									'page_id' => array(
										'type'        => 'integer',
										'description' => 'ID of a page or post to link to. Preferred over url: the link then follows the page if its slug ever changes.',
									),
									'url'     => array(
										'type'        => 'string',
										'description' => 'Explicit URL, for external links or anchors. Ignored when page_id is given.',
									),
									'parent'  => array(
										'type'        => 'integer',
										'description' => '1-based position, within this same items array, of the item this one sits under. Omit for a top-level item. The parent must appear earlier in the array than its child.',
									),
									'target'  => array(
										'type'        => 'string',
										'description' => 'Set to "_blank" to open in a new tab.',
									),
								),
								'required'   => array( 'title' ),
							),
						),
						'location'          => array(
							'type'        => 'string',
							'description' => 'Theme location slug to assign this menu to, e.g. "main_navigation". Get the valid slugs from fdj/get-theme-info rather than guessing; an unregistered slug is refused.',
						),
						'dry_run'           => array(
							'type'        => 'boolean',
							'description' => 'Preview without saving. Defaults to false.',
							'default'     => false,
						),
					),
					'required'   => array( 'menu_name' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'menu_id'       => array( 'type' => 'integer' ),
						'menu_name'     => array( 'type' => 'string' ),
						'created_menu'  => array( 'type' => 'boolean' ),
						'items'         => array( 'type' => 'array' ),
						'location'      => array( 'type' => 'string' ),
						'dry_run'       => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_manage_menu' ),
				'permission_callback' => array( __CLASS__, 'can_edit_theme_options' ),
			),

			'fdj/avada-reset-caches' => array(
				'is_write'            => true,
				'requires'            => 'avada',
				'label'               => 'Reset Avada Caches',
				'description'         => 'Regenerate Avada\'s compiled CSS and clear its caches. Avada compiles shortcode style attributes and Theme Options into its own cached CSS server-side, and a write made through these abilities does not trigger that regeneration the way saving in wp-admin does. The visible symptom is a style change that saves correctly and reports success while the live page keeps rendering the old value, which reads as a failed write and sends you looking for a bug that is not there. Run this after any styling or Theme Options write, then verify against the live page.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ran'     => array( 'type' => 'array' ),
						'skipped' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_avada_reset_caches' ),
				'permission_callback' => array( __CLASS__, 'can_edit_theme_options' ),
			),
		);
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
					/*
					 * Both keys are set explicitly and on purpose.
					 *
					 * On WP 6.9 and 7.0 there is no `meta.public` key at all;
					 * `show_in_rest` defaults to false and MCP visibility comes
					 * from `meta.mcp.public`. Later core versions added
					 * `meta.public` as a shorthand that seeds both. Setting the
					 * specific keys works on every version, because explicit
					 * values win over the shorthand.
					 */
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
	 * Permission callbacks
	 * ----------------------------------------------------------------- */

	/**
	 * Can the current user edit the referenced post?
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public static function can_edit_post( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( ! $post_id ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Can the current user edit posts at all?
	 *
	 * @return bool
	 */
	public static function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Can the current user create the requested post type?
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public static function can_create_post( $input = array() ) {
		$post_type     = isset( $input['post_type'] ) ? $input['post_type'] : 'page';
		$post_type_obj = get_post_type_object( $post_type );

		if ( ! $post_type_obj ) {
			return false;
		}

		$cap = isset( $post_type_obj->cap->create_posts ) ? $post_type_obj->cap->create_posts : 'publish_posts';

		return current_user_can( $cap );
	}

	/**
	 * Can the current user delete the referenced post?
	 *
	 * @param array $input Ability input.
	 * @return bool
	 */
	public static function can_delete_post( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( ! $post_id ) {
			return false;
		}

		return current_user_can( 'delete_post', $post_id );
	}

	/**
	 * Can the current user upload media?
	 *
	 * @return bool
	 */
	public static function can_upload_files() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Can the current user read site-wide configuration options?
	 *
	 * @return bool
	 */
	public static function can_manage_options() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Can the current user change theme-level configuration?
	 *
	 * Menus, menu locations and theme mods are gated on edit_theme_options
	 * rather than manage_options, so an Editor-with-appearance-access role
	 * is not locked out of work it can already do in wp-admin.
	 *
	 * @return bool
	 */
	public static function can_edit_theme_options() {
		return current_user_can( 'edit_theme_options' );
	}

	/* -----------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Refuse a write if the post changed since the caller last read it.
	 *
	 * Client sites have humans in them. Without this, an edit made in wp-admin
	 * two minutes ago is silently destroyed by a write based on a stale read.
	 *
	 * @param WP_Post $post  Post being written to.
	 * @param array   $input Ability input.
	 * @return true|WP_Error
	 */
	private static function check_not_stale( $post, $input ) {
		if ( empty( $input['expected_modified'] ) ) {
			return true;
		}

		if ( (string) $post->post_modified !== (string) $input['expected_modified'] ) {
			return new WP_Error(
				'fdj_stale_write',
				sprintf(
					'Refused: this post changed after you read it. You expected post_modified "%s" but it is now "%s". Re-read the post and redo the edit against current content.',
					(string) $input['expected_modified'],
					(string) $post->post_modified
				)
			);
		}

		return true;
	}

	/**
	 * Whether an option name falls under an allowed prefix.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function is_safe_option_name( $name ) {
		foreach ( self::SAFE_OPTION_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Collect excerpts around each occurrence of a literal needle.
	 *
	 * @param string $haystack Text to scan.
	 * @param string $needle   Literal string.
	 * @param int    $context  Characters of context each side.
	 * @return array{count:int,excerpts:array}
	 */
	private static function find_excerpts( $haystack, $needle, $context = 400 ) {
		$context  = max( 0, min( 4000, (int) $context ) );
		$excerpts = array();
		$count    = 0;
		$offset   = 0;
		$step     = max( 1, strlen( $needle ) );

		while ( false !== ( $pos = strpos( $haystack, $needle, $offset ) ) ) {
			$count++;

			if ( count( $excerpts ) < self::MAX_MATCHES ) {
				$start      = max( 0, $pos - $context );
				$excerpts[] = array(
					'offset'  => $pos,
					'excerpt' => substr( $haystack, $start, ( $pos - $start ) + $step + $context ),
				);
			}

			$offset = $pos + $step;
		}

		return array(
			'count'    => $count,
			'excerpts' => $excerpts,
		);
	}

	/**
	 * Flat-scan post content for Fusion Builder shortcode tags.
	 *
	 * Finds every [fusion_*] opening tag and its attributes in a single regex
	 * pass rather than building a true nested tree. That is enough to locate
	 * and describe elements; it does not resolve which closing tag belongs to
	 * which opening tag, so text_preview is a best-effort look at the text
	 * immediately following an element, not a guaranteed match to its own
	 * inner content only.
	 *
	 * @param string $content   Raw post_content.
	 * @param string $only_type Optional. Restrict to one shortcode tag.
	 * @return array
	 */
	private static function parse_fusion_elements( $content, $only_type = '' ) {
		$pattern = '/\[(fusion_[a-z_]+)((?:\s+[^\]]*?)?)(\s*\/)?\]/s';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$counts  = array();
		$results = array();

		foreach ( $matches[1] as $i => $tag_match ) {
			$tag = $tag_match[0];

			if ( '' !== $only_type && $tag !== $only_type ) {
				continue;
			}

			$counts[ $tag ] = isset( $counts[ $tag ] ) ? $counts[ $tag ] + 1 : 0;

			$raw_atts   = trim( $matches[2][ $i ][0] );
			$attributes = $raw_atts ? shortcode_parse_atts( $raw_atts ) : array();
			$attributes = is_array( $attributes ) ? $attributes : array();

			$literal_overrides = array();

			foreach ( self::STYLE_ATTRIBUTE_KEYS as $key ) {
				if ( isset( $attributes[ $key ] ) && '' !== $attributes[ $key ] && 0 !== stripos( (string) $attributes[ $key ], 'var(--awb-' ) ) {
					$literal_overrides[] = $key;
				}
			}

			$tag_end      = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
			$next_bracket = strpos( $content, '[', $tag_end );
			$preview_end  = false !== $next_bracket ? $next_bracket : min( strlen( $content ), $tag_end + 200 );
			$preview      = trim( wp_strip_all_tags( substr( $content, $tag_end, $preview_end - $tag_end ) ) );

			$results[] = array(
				'element_type'      => $tag,
				'occurrence'        => $counts[ $tag ],
				'text_preview'      => mb_substr( $preview, 0, 120 ),
				'is_global_ref'     => ( 'fusion_global' === $tag ),
				'global_id'         => ( 'fusion_global' === $tag && isset( $attributes['id'] ) ) ? (int) $attributes['id'] : 0,
				'literal_overrides' => $literal_overrides,
				'attributes'        => $attributes,
			);
		}

		return $results;
	}

	/* -----------------------------------------------------------------
	 * Execute callbacks
	 * ----------------------------------------------------------------- */

	/**
	 * Fetch one post or page, optionally only the regions matching a search.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_post( $input = array() ) {
		$post = get_post( (int) $input['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$content = $post->post_content;
		$search  = isset( $input['search'] ) ? (string) $input['search'] : '';

		$out = array(
			'post_id'        => $post->ID,
			'post_type'      => $post->post_type,
			'title'          => get_the_title( $post ),
			'status'         => $post->post_status,
			'content_length' => strlen( $content ),
			'edit_url'       => (string) get_edit_post_link( $post->ID, 'raw' ),
			'view_url'       => (string) get_permalink( $post->ID ),
			'modified'       => $post->post_modified,
		);

		if ( '' !== $search ) {
			$found = self::find_excerpts( $content, $search, isset( $input['context_chars'] ) ? $input['context_chars'] : 400 );

			$out['match_count'] = $found['count'];
			$out['matches']     = $found['excerpts'];

			// Only ship the whole body if explicitly asked. The entire point of
			// searching is to avoid moving a 100KB page-builder blob around.
			if ( ! empty( $input['include_content'] ) ) {
				$out['content'] = $content;
			}

			return $out;
		}

		if ( ! isset( $input['include_content'] ) || $input['include_content'] ) {
			$out['content'] = $content;
		}

		return $out;
	}

	/**
	 * List or search posts and pages.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute_list_posts( $input = array() ) {
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );

		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'page';

		/*
		 * WP_Query answers a query for an unregistered post type with an empty
		 * result set, which is byte-identical to a real post type that happens
		 * to hold nothing. That is the worst kind of wrong answer: it looks like
		 * evidence of absence. Refuse the guess instead, and point at the
		 * ability that can list the names that actually exist.
		 */
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'fdj_post_type_not_found',
				sprintf(
					'No post type named "%s" is registered on this site, so an empty result here would have meant nothing. Run fdj/list-post-types to see the names that do exist.',
					$post_type
				)
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => isset( $input['status'] ) ? $input['status'] : 'any',
				's'              => isset( $input['search'] ) ? $input['search'] : '',
				'posts_per_page' => $per_page,
				'no_found_rows'  => true,
			)
		);

		$results = array();

		foreach ( $query->posts as $post ) {
			$results[] = array(
				'post_id'  => $post->ID,
				'title'    => get_the_title( $post ),
				'status'   => $post->post_status,
				'view_url' => (string) get_permalink( $post->ID ),
			);
		}

		return $results;
	}

	/**
	 * Find every post whose content contains a literal string.
	 *
	 * Deliberately a direct query rather than WP_Query: core search splits on
	 * whitespace and matches words, which is wrong when you are looking for an
	 * exact fragment such as a shortcode attribute or a phone number.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_search_content( $input = array() ) {
		global $wpdb;

		$needle = isset( $input['search'] ) ? (string) $input['search'] : '';

		if ( '' === $needle ) {
			return new WP_Error( 'fdj_empty_search', 'A non-empty search string is required.' );
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 25;
		$per_page = max( 1, min( 100, $per_page ) );

		// LIKE BINARY, not LIKE: the default collation matches case-insensitively,
		// which used to let a post through this filter with occurrences reported
		// as 0, because the substr_count() below is case-sensitive. Matching case
		// at the SQL layer keeps "found in N posts" and "occurrences" consistent
		// with each other, and with what fdj/replace-in-post will actually do.
		$where  = 'WHERE post_content LIKE BINARY %s AND post_status NOT IN ( %s, %s )';
		$params = array( '%' . $wpdb->esc_like( $needle ) . '%', 'auto-draft', 'inherit' );

		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : 'any';

		if ( '' !== $post_type && 'any' !== $post_type ) {
			$where   .= ' AND post_type = %s';
			$params[] = $post_type;
		} else {
			$where .= " AND post_type NOT IN ( 'revision', 'nav_menu_item' )";
		}

		$status = isset( $input['status'] ) ? (string) $input['status'] : 'any';

		if ( '' !== $status && 'any' !== $status ) {
			$where   .= ' AND post_status = %s';
			$params[] = $status;
		}

		$params[] = $per_page;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status, post_content
				 FROM {$wpdb->posts} {$where}
				 ORDER BY post_modified DESC
				 LIMIT %d",
				$params
			)
		);
		// phpcs:enable

		$results = array();
		$total   = 0;

		foreach ( (array) $rows as $row ) {

			// Never surface a post this user could not open in wp-admin.
			if ( ! current_user_can( 'edit_post', $row->ID ) ) {
				continue;
			}

			$occurrences = substr_count( $row->post_content, $needle );
			$total      += $occurrences;

			$results[] = array(
				'post_id'     => (int) $row->ID,
				'title'       => $row->post_title,
				'post_type'   => $row->post_type,
				'status'      => $row->post_status,
				'occurrences' => $occurrences,
				'edit_url'    => (string) get_edit_post_link( $row->ID, 'raw' ),
			);
		}

		return array(
			'total_posts'       => count( $results ),
			'total_occurrences' => $total,
			'results'           => $results,
		);
	}

	/**
	 * Targeted find and replace inside one post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_replace_in_post( $input = array() ) {
		$post = get_post( (int) $input['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$stale = self::check_not_stale( $post, $input );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$search = (string) $input['search'];

		if ( '' === $search ) {
			return new WP_Error( 'fdj_empty_search', 'The search string cannot be empty.' );
		}

		$replace = (string) $input['replace'];
		$content = $post->post_content;
		$found   = self::find_excerpts( $content, $search, 200 );

		if ( 0 === $found['count'] ) {
			return new WP_Error(
				'fdj_no_match',
				'Nothing to do: that string does not appear in this post. Check for HTML entities, curly quotes, or non-breaking spaces, which often differ from what is displayed.'
			);
		}

		// Guard before writing, not after.
		if ( isset( $input['expect_count'] ) && (int) $input['expect_count'] !== $found['count'] ) {
			return new WP_Error(
				'fdj_count_mismatch',
				sprintf(
					'Refused: you expected %d occurrence(s) but there are %d. Nothing was written. Re-check with dry_run, or set expect_count to %d if that is genuinely what you want.',
					(int) $input['expect_count'],
					$found['count'],
					$found['count']
				)
			);
		}

		$limit    = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : -1;
		$replaced = ( $limit > 0 ) ? min( $limit, $found['count'] ) : $found['count'];

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'post_id'  => $post->ID,
				'found'    => $found['count'],
				'replaced' => 0,
				'dry_run'  => true,
				'previews' => $found['excerpts'],
				'modified' => $post->post_modified,
				'view_url' => (string) get_permalink( $post->ID ),
			);
		}

		$new_content = ( $limit > 0 )
			? implode( $replace, explode( $search, $content, $limit + 1 ) )
			: str_replace( $search, $replace, $content );

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $new_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $post->ID );

		return array(
			'post_id'  => (int) $result,
			'found'    => $found['count'],
			'replaced' => $replaced,
			'dry_run'  => false,
			'previews' => array(),
			'modified' => $updated ? $updated->post_modified : '',
			'view_url' => (string) get_permalink( $result ),
		);
	}

	/**
	 * Update an existing post or page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_post( $input = array() ) {
		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$stale = self::check_not_stale( $post, $input );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$update = array(
			'ID'           => $post_id,
			'post_content' => $input['content'],
		);

		if ( isset( $input['title'] ) ) {
			$update['post_title'] = $input['title'];
		}

		if ( isset( $input['status'] ) ) {
			$update['post_status'] = $input['status'];
		}

		$result = wp_update_post( $update, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $result );

		return array(
			'post_id'  => (int) $result,
			'status'   => get_post_status( $result ),
			'modified' => $updated ? $updated->post_modified : '',
			'view_url' => (string) get_permalink( $result ),
		);
	}

	/**
	 * Create a post or page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_create_post( $input = array() ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $input['title'],
				'post_content' => $input['content'],
				'post_type'    => isset( $input['post_type'] ) ? $input['post_type'] : 'page',
				'post_status'  => isset( $input['status'] ) ? $input['status'] : 'draft',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return array(
			'post_id'  => (int) $post_id,
			'status'   => get_post_status( $post_id ),
			'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
			'view_url' => (string) get_permalink( $post_id ),
		);
	}

	/**
	 * List stored revisions for a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_revisions( $input = array() ) {
		$post_id = (int) $input['post_id'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );

		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => $per_page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$results = array();

		foreach ( (array) $revisions as $revision ) {
			$author = get_userdata( (int) $revision->post_author );

			$results[] = array(
				'revision_id' => (int) $revision->ID,
				'date'        => $revision->post_modified,
				'author'      => $author ? $author->user_login : '(unknown)',
				'is_autosave' => (bool) wp_is_post_autosave( $revision ),
				'title'       => $revision->post_title,
			);
		}

		return $results;
	}

	/**
	 * List Fusion Builder elements on a page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_fusion_builder_elements( $input = array() ) {
		$post = get_post( (int) $input['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$only_type = isset( $input['element_type'] ) ? (string) $input['element_type'] : '';

		return self::parse_fusion_elements( $post->post_content, $only_type );
	}

	/**
	 * Find option names matching a search term, within the safe-prefix allowlist.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_options( $input = array() ) {
		global $wpdb;

		$search = isset( $input['search'] ) ? (string) $input['search'] : '';

		if ( '' === $search ) {
			return new WP_Error( 'fdj_empty_search', 'A non-empty search string is required.' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS value_length
				 FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				 ORDER BY option_name
				 LIMIT 200",
				'%' . $wpdb->esc_like( $search ) . '%'
			)
		);
		// phpcs:enable

		$results = array();

		foreach ( (array) $rows as $row ) {
			if ( ! self::is_safe_option_name( $row->option_name ) ) {
				continue;
			}

			$results[] = array(
				'option_name'  => $row->option_name,
				'value_length' => (int) $row->value_length,
			);
		}

		return $results;
	}

	/**
	 * Read one option by exact name, within the safe-prefix allowlist.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_option( $input = array() ) {
		$name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';

		if ( '' === $name ) {
			return new WP_Error( 'fdj_empty_option_name', 'option_name is required.' );
		}

		if ( ! self::is_safe_option_name( $name ) ) {
			return new WP_Error(
				'fdj_option_not_allowed',
				sprintf(
					'Refused: "%s" is outside the allowed prefixes (%s). This ability is scoped to theme and widget configuration on purpose; it does not read arbitrary options such as stored credentials.',
					$name,
					implode( ', ', self::SAFE_OPTION_PREFIXES )
				)
			);
		}

		return array(
			'option_name' => $name,
			'value'       => get_option( $name ),
		);
	}

	/**
	 * Lean, list-friendly summary of one attachment. execute_get_media() builds
	 * on top of this rather than duplicating it.
	 *
	 * @param WP_Post $post Attachment post.
	 * @return array
	 */
	private static function summarize_attachment( $post ) {
		$meta = wp_get_attachment_metadata( $post->ID );
		$file = get_attached_file( $post->ID );

		$file_size = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;

		// Older attachments, and non-image files in general, were not always
		// given a stored filesize at upload time. Fall back to a real stat
		// only when metadata does not already have the answer.
		if ( ! $file_size && $file && file_exists( $file ) ) {
			$file_size = (int) filesize( $file );
		}

		return array(
			'attachment_id' => (int) $post->ID,
			'title'         => get_the_title( $post ),
			'filename'      => $file ? wp_basename( $file ) : '',
			'mime_type'     => $post->post_mime_type,
			'url'           => (string) wp_get_attachment_url( $post->ID ),
			'alt_text'      => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'file_size'     => $file_size,
			'date'          => $post->post_date,
		);
	}

	/**
	 * Search or list media library attachments.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute_list_media( $input = array() ) {
		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => isset( $input['search'] ) ? (string) $input['search'] : '',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => true,
		);

		if ( ! empty( $input['mime_type'] ) ) {
			$args['post_mime_type'] = (string) $input['mime_type'];
		}

		$query   = new WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $post ) {
			$results[] = self::summarize_attachment( $post );
		}

		return $results;
	}

	/**
	 * Fetch one media attachment by ID, with every registered image size.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_media( $input = array() ) {
		$post = get_post( isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0 );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'fdj_not_found', 'No media attachment found with that ID.' );
		}

		$out                   = self::summarize_attachment( $post );
		$out['caption']        = $post->post_excerpt;
		$out['description']    = $post->post_content;
		$out['parent_post_id'] = (int) $post->post_parent;

		$meta  = wp_get_attachment_metadata( $post->ID );
		$sizes = array();

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( array_keys( $meta['sizes'] ) as $size_name ) {
				$src = wp_get_attachment_image_src( $post->ID, $size_name );

				if ( $src ) {
					$sizes[ $size_name ] = array(
						'url'    => $src[0],
						'width'  => $src[1],
						'height' => $src[2],
					);
				}
			}
		}

		$out['sizes'] = $sizes;

		return $out;
	}

	/**
	 * Read every (or every requested) meta key on one post/page/product.
	 *
	 * Deliberately not restricted to a safe-prefix allowlist the way
	 * fdj/get-option is: wp_options is where real secrets and API keys live,
	 * post meta on a product or page essentially never is, it is what plugins
	 * use to store structured configuration and content. Gated on can_edit_post
	 * like everything else that reads or writes one specific post, same as
	 * post_content already is, this is not a new category of exposure.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_post_meta( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'fdj_not_found', 'No post found with that ID.' );
		}

		$requested_keys = ( isset( $input['keys'] ) && is_array( $input['keys'] ) && $input['keys'] )
			? array_map( 'sanitize_text_field', $input['keys'] )
			: array();

		$all = get_post_meta( $post_id );
		$out = array();

		foreach ( $all as $key => $values ) {
			if ( $requested_keys && ! in_array( $key, $requested_keys, true ) ) {
				continue;
			}

			$out[] = array(
				'key'   => $key,
				'value' => ( 1 === count( $values ) ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values ),
			);
		}

		return array(
			'post_id' => $post_id,
			'meta'    => $out,
		);
	}

	/**
	 * Roll a post back to a revision.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_restore_revision( $input = array() ) {
		$post_id     = (int) $input['post_id'];
		$revision_id = (int) $input['revision_id'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$revision = wp_get_post_revision( $revision_id );

		if ( ! $revision ) {
			return new WP_Error( 'fdj_revision_not_found', 'No revision found with that ID.' );
		}

		// Without this check, any revision on the site could be pushed into any post.
		if ( (int) $revision->post_parent !== $post_id ) {
			return new WP_Error(
				'fdj_revision_mismatch',
				sprintf( 'Revision %d belongs to post %d, not post %d.', $revision_id, (int) $revision->post_parent, $post_id )
			);
		}

		$restored = wp_restore_post_revision( $revision_id );

		if ( ! $restored ) {
			return new WP_Error( 'fdj_restore_failed', 'WordPress declined to restore that revision.' );
		}

		$updated = get_post( $post_id );

		return array(
			'post_id'  => $post_id,
			'restored' => $revision_id,
			'modified' => $updated ? $updated->post_modified : '',
			'view_url' => (string) get_permalink( $post_id ),
		);
	}

	/**
	 * Update one Fusion Builder element's attributes, or remove it.
	 *
	 * Re-scans post_content with the same pattern fdj/list-fusion-builder-elements
	 * uses, so element_type + occurrence resolve to the same element in both. Does
	 * not attempt true nested-tree parsing: for a paired tag it removes up to the
	 * next matching close tag, which is correct for ordinary leaf elements but
	 * wrong for a tag that can nest inside itself, so those are refused outright
	 * rather than risking a wrong match.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_fusion_element( $input = array() ) {
		$post = get_post( (int) $input['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$stale = self::check_not_stale( $post, $input );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$element_type = (string) $input['element_type'];
		$occurrence   = isset( $input['occurrence'] ) ? (int) $input['occurrence'] : 0;
		$remove       = ! empty( $input['remove'] );
		$attributes   = isset( $input['attributes'] ) && is_array( $input['attributes'] ) ? $input['attributes'] : array();

		if ( $remove && ! empty( $attributes ) ) {
			return new WP_Error( 'fdj_conflicting_input', 'Pass either attributes or remove, not both.' );
		}

		if ( ! $remove && empty( $attributes ) ) {
			return new WP_Error( 'fdj_empty_edit', 'Nothing to do: pass attributes to set, or remove to delete the element.' );
		}

		if ( $remove && in_array( $element_type, self::UNSAFE_REMOVAL_TAGS, true ) ) {
			return new WP_Error(
				'fdj_unsafe_removal',
				sprintf( '%s can contain nested elements of its own kind, so a targeted removal risks taking more of the page with it than intended. Use fdj/update-post-content for this one.', $element_type )
			);
		}

		$content = $post->post_content;
		$pattern = '/\[(fusion_[a-z_]+)((?:\s+[^\]]*?)?)(\s*\/)?\]/s';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return new WP_Error( 'fdj_element_not_found', 'No Fusion Builder elements found on this page at all.' );
		}

		$seen  = -1;
		$found = null;

		foreach ( $matches[1] as $i => $tag_match ) {
			if ( $tag_match[0] !== $element_type ) {
				continue;
			}

			$seen++;

			if ( $seen === $occurrence ) {
				$found = $i;
				break;
			}
		}

		if ( null === $found ) {
			return new WP_Error(
				'fdj_element_not_found',
				sprintf( 'No %s at occurrence %d. This page has %d element(s) of that type. Re-run fdj/list-fusion-builder-elements, the page may have changed.', $element_type, $occurrence, $seen + 1 )
			);
		}

		$tag_start    = $matches[0][ $found ][1];
		$tag_text     = $matches[0][ $found ][0];
		$tag_end      = $tag_start + strlen( $tag_text );
		$self_closing = '' !== trim( (string) $matches[3][ $found ][0] );

		if ( $remove ) {
			$action = 'removed';

			if ( $self_closing ) {
				$span_start = $tag_start;
				$span_end   = $tag_end;
			} else {
				$close_tag = '[/' . $element_type . ']';
				$close_pos = strpos( $content, $close_tag, $tag_end );

				if ( false === $close_pos ) {
					return new WP_Error(
						'fdj_close_tag_not_found',
						sprintf( 'Found the opening %s tag but no matching %s. Refusing to guess where it ends.', $element_type, $close_tag )
					);
				}

				$span_start = $tag_start;
				$span_end   = $close_pos + strlen( $close_tag );
			}

			$before   = substr( $content, $span_start, $span_end - $span_start );
			$after    = '';
			$new_full = substr( $content, 0, $span_start ) . substr( $content, $span_end );
		} else {
			$action   = 'updated';
			$raw_atts = trim( $matches[2][ $found ][0] );
			$existing = $raw_atts ? shortcode_parse_atts( $raw_atts ) : array();
			$existing = is_array( $existing ) ? $existing : array();
			$merged   = array_merge( $existing, $attributes );

			// A key set to null means "clear this back to Avada's default", not
			// "set it to an empty string". Fusion Builder's own element defaults
			// only kick in when the attribute is absent, so drop the key entirely
			// rather than writing e.g. padding_top="".
			$merged = array_filter(
				$merged,
				function ( $value ) {
					return null !== $value;
				}
			);

			$pairs = array();

			foreach ( $merged as $key => $value ) {
				$pairs[] = sprintf( '%s="%s"', $key, esc_attr( (string) $value ) );
			}

			$new_tag  = '[' . $element_type . ( $pairs ? ' ' . implode( ' ', $pairs ) : '' ) . ( $self_closing ? ' /' : '' ) . ']';
			$before   = $tag_text;
			$after    = $new_tag;
			$new_full = substr( $content, 0, $tag_start ) . $new_tag . substr( $content, $tag_end );
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'post_id'      => $post->ID,
				'element_type' => $element_type,
				'occurrence'   => $occurrence,
				'action'       => $action,
				'dry_run'      => true,
				'before'       => $before,
				'after'        => $after,
				'modified'     => $post->post_modified,
				'view_url'     => (string) get_permalink( $post->ID ),
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $new_full,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = get_post( $post->ID );

		return array(
			'post_id'      => (int) $result,
			'element_type' => $element_type,
			'occurrence'   => $occurrence,
			'action'       => $action,
			'dry_run'      => false,
			'before'       => $before,
			'after'        => $after,
			'modified'     => $updated ? $updated->post_modified : '',
			'view_url'     => (string) get_permalink( $result ),
		);
	}

	/**
	 * Download a remote file into the media library.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_upload_media( $input = array() ) {
		$source_url = isset( $input['source_url'] ) ? (string) $input['source_url'] : '';

		if ( '' === $source_url || ! wp_http_validate_url( $source_url ) ) {
			return new WP_Error( 'fdj_invalid_url', 'source_url must be a valid, publicly reachable URL.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title         = isset( $input['title'] ) ? (string) $input['title'] : '';
		$attachment_id = media_sideload_image( $source_url, 0, ( '' !== $title ? $title : null ), 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		);
	}

	/**
	 * Trash, or permanently delete, a post or page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_delete_post( $input = array() ) {
		$post = get_post( (int) $input['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		$stale = self::check_not_stale( $post, $input );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$force  = ! empty( $input['force'] );
		$result = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );

		if ( ! $result ) {
			return new WP_Error( 'fdj_delete_failed', 'WordPress declined to delete that post.' );
		}

		return array(
			'post_id' => $post->ID,
			'action'  => $force ? 'deleted' : 'trashed',
		);
	}

	/**
	 * Find and replace a literal string inside one string field of an option.
	 *
	 * Options like widget_text or fusion_options are nested arrays, not a single
	 * string, so this walks a caller-supplied path to one leaf field and edits
	 * only that field. The rest of the option is written back exactly as it was
	 * read, by reference, so there is no chance of a sibling key being dropped
	 * or reshaped in transit.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_replace_in_option( $input = array() ) {
		$name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';

		if ( '' === $name ) {
			return new WP_Error( 'fdj_empty_option_name', 'option_name is required.' );
		}

		if ( ! self::is_safe_option_name( $name ) ) {
			return new WP_Error(
				'fdj_option_not_allowed',
				sprintf(
					'Refused: "%s" is outside the allowed prefixes (%s).',
					$name,
					implode( ', ', self::SAFE_OPTION_PREFIXES )
				)
			);
		}

		$path = isset( $input['path'] ) && is_array( $input['path'] ) ? array_values( $input['path'] ) : array();

		if ( empty( $path ) ) {
			return new WP_Error( 'fdj_empty_path', 'path is required: the array of keys leading to the one string field to edit.' );
		}

		$value = get_option( $name, null );

		if ( null === $value ) {
			return new WP_Error( 'fdj_option_not_found', sprintf( 'No option named "%s" exists.', $name ) );
		}

		$last_key = array_pop( $path );
		$cursor   = &$value;

		foreach ( $path as $key ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
				return new WP_Error(
					'fdj_path_not_found',
					sprintf( 'Key "%s" not found while walking path in option "%s". Re-check with fdj/get-option.', $key, $name )
				);
			}

			$cursor = &$cursor[ $key ];
		}

		if ( ! is_array( $cursor ) || ! array_key_exists( $last_key, $cursor ) ) {
			return new WP_Error(
				'fdj_path_not_found',
				sprintf( 'Key "%s" not found while walking path in option "%s". Re-check with fdj/get-option.', $last_key, $name )
			);
		}

		if ( ! is_string( $cursor[ $last_key ] ) ) {
			return new WP_Error(
				'fdj_not_a_string',
				sprintf( 'The value at that path in "%s" is not a string, so there is nothing to find-and-replace inside it.', $name )
			);
		}

		$search = isset( $input['search'] ) ? (string) $input['search'] : '';

		if ( '' === $search ) {
			return new WP_Error( 'fdj_empty_search', 'The search string cannot be empty.' );
		}

		$replace    = isset( $input['replace'] ) ? (string) $input['replace'] : '';
		$field      = $cursor[ $last_key ];
		$found      = self::find_excerpts( $field, $search, 200 );
		$full_path  = array_merge( $path, array( $last_key ) );

		if ( 0 === $found['count'] ) {
			return new WP_Error( 'fdj_no_match', 'Nothing to do: that string does not appear at this path.' );
		}

		if ( isset( $input['expect_count'] ) && (int) $input['expect_count'] !== $found['count'] ) {
			return new WP_Error(
				'fdj_count_mismatch',
				sprintf(
					'Refused: you expected %d occurrence(s) but there are %d. Nothing was written.',
					(int) $input['expect_count'],
					$found['count']
				)
			);
		}

		$new_field = str_replace( $search, $replace, $field );

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'option_name' => $name,
				'path'        => $full_path,
				'found'       => $found['count'],
				'replaced'    => 0,
				'dry_run'     => true,
				'before'      => $field,
				'after'       => $new_field,
			);
		}

		$cursor[ $last_key ] = $new_field;
		unset( $cursor );

		$saved = update_option( $name, $value );

		// update_option() returns false both on failure and when the new value
		// is identical to what was already stored. Only treat it as a real
		// failure if a fresh read does not actually reflect the intended write.
		if ( ! $saved && get_option( $name ) !== $value ) {
			return new WP_Error( 'fdj_save_failed', 'WordPress declined to save the updated option.' );
		}

		return array(
			'option_name' => $name,
			'path'        => $full_path,
			'found'       => $found['count'],
			'replaced'    => $found['count'],
			'dry_run'     => false,
			'before'      => $field,
			'after'       => $new_field,
		);
	}

	/**
	 * Read the active theme and the extension points a build needs to target.
	 *
	 * @return array
	 */
	public static function execute_get_theme_info() {
		$theme = wp_get_theme();

		$assigned  = get_theme_mod( 'nav_menu_locations', array() );
		$locations = array();

		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$menu_id = isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0;
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;

			$locations[] = array(
				'slug'         => (string) $slug,
				'label'        => (string) $label,
				'menu_id'      => $menu_id,
				'menu_name'    => $menu ? $menu->name : '',
			);
		}

		$sidebars = array();

		if ( ! empty( $GLOBALS['wp_registered_sidebars'] ) ) {
			foreach ( $GLOBALS['wp_registered_sidebars'] as $id => $sidebar ) {
				$sidebars[] = array(
					'id'   => (string) $id,
					'name' => isset( $sidebar['name'] ) ? (string) $sidebar['name'] : '',
				);
			}
		}

		$builders = array();

		if ( defined( 'FUSION_BUILDER_VERSION' ) ) {
			$builders[] = 'fusion-builder ' . FUSION_BUILDER_VERSION;
		}

		if ( defined( 'AVADA_VERSION' ) ) {
			$builders[] = 'avada ' . AVADA_VERSION;
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$builders[] = 'elementor ' . ELEMENTOR_VERSION;
		}

		$parent = $theme->parent();

		return array(
			'stylesheet'     => (string) get_stylesheet(),
			'template'       => (string) get_template(),
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'is_child_theme' => (bool) $parent,
			'parent'         => $parent ? (string) $parent->get( 'Name' ) : '',
			'menu_locations' => $locations,
			'sidebars'       => $sidebars,
			'builders'       => $builders,
		);
	}

	/**
	 * Set one value at a path inside an allowlisted option.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_set_option_value( $input = array() ) {
		$name = isset( $input['option_name'] ) ? (string) $input['option_name'] : '';

		if ( '' === $name ) {
			return new WP_Error( 'fdj_empty_option_name', 'option_name is required.' );
		}

		if ( ! self::is_safe_option_name( $name ) ) {
			return new WP_Error(
				'fdj_option_not_allowed',
				sprintf(
					'Refused: "%s" is outside the allowed prefixes (%s).',
					$name,
					implode( ', ', self::SAFE_OPTION_PREFIXES )
				)
			);
		}

		$sentinel = new stdClass();
		$stored   = get_option( $name, $sentinel );
		$existed  = ( $stored !== $sentinel );

		if ( ! $existed ) {
			if ( empty( $input['create_option'] ) ) {
				return new WP_Error(
					'fdj_option_not_found',
					sprintf( 'No option named "%s" exists. Pass create_option to create it, but check the name first.', $name )
				);
			}

			$stored = array();
		}

		$path           = isset( $input['path'] ) && is_array( $input['path'] ) ? array_values( $input['path'] ) : array();
		$value          = self::normalize_option_value( isset( $input['value'] ) ? $input['value'] : null );
		$create_missing = ! isset( $input['create_missing'] ) || ! empty( $input['create_missing'] );

		// An empty path replaces the whole option.
		if ( empty( $path ) ) {
			$before = $existed ? $stored : null;
			$after  = $value;
		} else {
			if ( $existed && ! is_array( $stored ) ) {
				return new WP_Error(
					'fdj_option_not_an_array',
					sprintf( 'Option "%s" holds a scalar, so there is no path to write into. Pass an empty path to replace it outright.', $name )
				);
			}

			$new      = is_array( $stored ) ? $stored : array();
			$last_key = array_pop( $path );
			$cursor   = &$new;

			foreach ( $path as $key ) {
				if ( ! array_key_exists( $key, $cursor ) || ! is_array( $cursor[ $key ] ) ) {
					if ( ! $create_missing && ! array_key_exists( $key, $cursor ) ) {
						unset( $cursor );

						return new WP_Error(
							'fdj_path_not_found',
							sprintf( 'Key "%s" does not exist in option "%s" and create_missing is false.', $key, $name )
						);
					}

					if ( array_key_exists( $key, $cursor ) && ! is_array( $cursor[ $key ] ) ) {
						unset( $cursor );

						return new WP_Error(
							'fdj_path_blocked',
							sprintf( 'Key "%s" in option "%s" holds a scalar, so the path cannot continue through it.', $key, $name )
						);
					}

					$cursor[ $key ] = array();
				}

				$cursor = &$cursor[ $key ];
			}

			if ( ! array_key_exists( $last_key, $cursor ) && ! $create_missing ) {
				unset( $cursor );

				return new WP_Error(
					'fdj_path_not_found',
					sprintf( 'Key "%s" does not exist in option "%s" and create_missing is false.', $last_key, $name )
				);
			}

			$before = array_key_exists( $last_key, $cursor ) ? $cursor[ $last_key ] : null;

			if ( array_key_exists( 'expect_current', $input ) ) {
				$expected = self::normalize_option_value( $input['expect_current'] );

				if ( $before !== $expected ) {
					unset( $cursor );

					return new WP_Error(
						'fdj_unexpected_current',
						sprintf(
							'Refused: expect_current did not match the value at that path in "%s". Re-read with fdj/get-option and redo the write against what is actually there.',
							$name
						)
					);
				}
			}

			$cursor[ $last_key ] = $value;
			unset( $cursor );

			$after  = $value;
			$stored = $new;
			$path[] = $last_key;
		}

		$full_path = empty( $input['path'] ) || ! is_array( $input['path'] ) ? array() : array_values( $input['path'] );

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'option_name' => $name,
				'path'        => $full_path,
				'created'     => ! $existed,
				'dry_run'     => true,
				'before'      => $before,
				'after'       => $after,
			);
		}

		$to_save = empty( $full_path ) ? $value : $stored;
		$saved   = update_option( $name, $to_save );

		// update_option() returns false both on failure and when the value is
		// unchanged, so only a fresh read that still disagrees is a real failure.
		if ( ! $saved && get_option( $name ) !== $to_save ) {
			return new WP_Error( 'fdj_save_failed', 'WordPress declined to save the updated option.' );
		}

		return array(
			'option_name' => $name,
			'path'        => $full_path,
			'created'     => ! $existed,
			'dry_run'     => false,
			'before'      => $before,
			'after'       => $after,
		);
	}

	/**
	 * Convert a decoded JSON value into the shape WordPress stores.
	 *
	 * JSON objects arrive as stdClass over REST; option and meta data is
	 * expected to be nested arrays, so a stored object would round-trip
	 * differently to the same data saved through wp-admin.
	 *
	 * @param mixed $value Decoded value.
	 * @return mixed
	 */
	private static function normalize_option_value( $value ) {
		if ( $value instanceof stdClass ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::normalize_option_value( $item );
			}
		}

		return $value;
	}

	/**
	 * Core settings this plugin is allowed to write, and how to sanitize each.
	 *
	 * Deliberately a fixed list rather than a prefix rule: these live in the
	 * same wp_options table as every credential a plugin has ever stashed there.
	 *
	 * @return array<string, string>
	 */
	private static function core_setting_types() {
		return array(
			'blogname'            => 'text',
			'blogdescription'     => 'text',
			'show_on_front'       => 'front',
			'page_on_front'       => 'post_id',
			'page_for_posts'      => 'post_id',
			'posts_per_page'      => 'int',
			'date_format'         => 'text',
			'time_format'         => 'text',
			'start_of_week'       => 'int',
			'timezone_string'     => 'text',
			'permalink_structure' => 'text',
			'blog_public'         => 'int',
			'site_icon'           => 'post_id',
		);
	}

	/**
	 * Write one allowlisted core setting.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_set_core_setting( $input = array() ) {
		$setting = isset( $input['setting'] ) ? (string) $input['setting'] : '';
		$types   = self::core_setting_types();

		if ( ! isset( $types[ $setting ] ) ) {
			return new WP_Error(
				'fdj_setting_not_allowed',
				sprintf( 'Refused: "%s" is not one of the allowed core settings (%s).', $setting, implode( ', ', array_keys( $types ) ) )
			);
		}

		$raw = isset( $input['value'] ) ? $input['value'] : '';

		switch ( $types[ $setting ] ) {
			case 'front':
				$value = ( 'posts' === $raw ) ? 'posts' : 'page';

				if ( ! in_array( $raw, array( 'page', 'posts' ), true ) ) {
					return new WP_Error( 'fdj_invalid_value', 'show_on_front must be "page" or "posts".' );
				}
				break;

			case 'post_id':
				$value = (int) $raw;

				if ( $value > 0 && ! get_post( $value ) ) {
					return new WP_Error( 'fdj_post_not_found', sprintf( 'No post exists with ID %d.', $value ) );
				}
				break;

			case 'int':
				$value = (int) $raw;
				break;

			default:
				$value = sanitize_text_field( (string) $raw );
				break;
		}

		$before = get_option( $setting );

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'setting' => $setting,
				'before'  => $before,
				'after'   => $value,
				'dry_run' => true,
			);
		}

		$saved = update_option( $setting, $value );

		if ( ! $saved && get_option( $setting ) != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- options round-trip ints as numeric strings.
			return new WP_Error( 'fdj_save_failed', sprintf( 'WordPress declined to save "%s".', $setting ) );
		}

		/*
		 * A permalink change is inert until the rewrite rules are rebuilt, and
		 * flushing alone is not enough: $wp_rewrite was constructed earlier in
		 * this request from the OLD structure, so a plain flush regenerates
		 * exactly the rules it already had. Confirmed on a real site, where the
		 * option saved correctly and every post 404'd on its new URL until the
		 * same call was run a second time. Hand the new structure to the
		 * rewrite object first, then flush.
		 */
		if ( 'permalink_structure' === $setting ) {
			global $wp_rewrite;

			if ( $wp_rewrite instanceof WP_Rewrite ) {
				$wp_rewrite->set_permalink_structure( $value );
				$wp_rewrite->flush_rules( false );
			} else {
				flush_rewrite_rules( false );
			}
		}

		return array(
			'setting' => $setting,
			'before'  => $before,
			'after'   => $value,
			'dry_run' => false,
		);
	}

	/**
	 * Whether a meta key may be written through this plugin.
	 *
	 * Post meta is where builders keep per-page layout, so a blanket ban on
	 * underscore-prefixed keys would block the exact keys this exists to reach.
	 * Core's own internal bookkeeping is excluded instead, with the two _wp_
	 * keys that are legitimately a builder's business allowed back in.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function is_writable_meta_key( $key ) {
		$allowed_wp = array( '_wp_page_template', '_wp_attachment_image_alt' );

		if ( in_array( $key, $allowed_wp, true ) ) {
			return true;
		}

		$blocked_prefixes = array( '_wp_', '_transient', '_site_transient', '_edit_lock', '_edit_last', '_oembed_' );

		foreach ( $blocked_prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return false;
			}
		}

		return '' !== $key;
	}

	/**
	 * Write or delete custom fields on one post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_post_meta( $input = array() ) {
		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );

		if ( ! $post ) {
			return new WP_Error( 'fdj_post_not_found', 'No post found with that ID.' );
		}

		$stale = self::check_not_stale( $post, $input );

		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$meta = isset( $input['meta'] ) ? $input['meta'] : null;

		if ( $meta instanceof stdClass ) {
			$meta = (array) $meta;
		}

		if ( ! is_array( $meta ) || empty( $meta ) ) {
			return new WP_Error( 'fdj_empty_meta', 'meta must be a non-empty object of meta_key => value pairs.' );
		}

		foreach ( array_keys( $meta ) as $key ) {
			if ( ! self::is_writable_meta_key( (string) $key ) ) {
				return new WP_Error(
					'fdj_meta_key_not_allowed',
					sprintf( 'Refused: "%s" is WordPress internal bookkeeping and is not writable through this ability. Nothing was written.', $key )
				);
			}
		}

		$dry_run = ! empty( $input['dry_run'] );
		$changes = array();

		foreach ( $meta as $key => $value ) {
			$key      = (string) $key;
			$existing = get_post_meta( $post->ID, $key, true );
			$value    = self::normalize_option_value( $value );
			$action   = ( null === $value ) ? 'delete' : 'set';

			$changes[] = array(
				'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'action'   => $action,
				'before'   => ( '' === $existing ) ? null : $existing,
				'after'    => $value,
			);

			if ( $dry_run ) {
				continue;
			}

			if ( 'delete' === $action ) {
				delete_post_meta( $post->ID, $key );
			} else {
				update_post_meta( $post->ID, $key, $value );
			}
		}

		return array(
			'post_id' => (int) $post->ID,
			'changes' => $changes,
			'dry_run' => $dry_run,
		);
	}

	/**
	 * Assign taxonomy terms to a post.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_set_post_terms( $input = array() ) {
		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );

		if ( ! $post ) {
			return new WP_Error( 'fdj_post_not_found', 'No post found with that ID.' );
		}

		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'fdj_taxonomy_not_found',
				sprintf( 'No taxonomy named "%s" is registered on this site.', $taxonomy )
			);
		}

		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return new WP_Error(
				'fdj_taxonomy_wrong_type',
				sprintf( 'Taxonomy "%s" does not apply to post type "%s".', $taxonomy, $post->post_type )
			);
		}

		$requested = isset( $input['terms'] ) && is_array( $input['terms'] ) ? array_values( $input['terms'] ) : array();

		$before = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
		$before = is_wp_error( $before ) ? array() : $before;

		$create_missing = ! empty( $input['create_missing'] );
		$term_ids       = array();
		$created        = array();

		foreach ( $requested as $name ) {
			$name = (string) $name;
			$term = get_term_by( 'slug', $name, $taxonomy );

			if ( ! $term ) {
				$term = get_term_by( 'name', $name, $taxonomy );
			}

			if ( ! $term ) {
				if ( ! $create_missing ) {
					return new WP_Error(
						'fdj_term_not_found',
						sprintf( 'No term "%s" exists in taxonomy "%s", and create_missing is false. Nothing was written.', $name, $taxonomy )
					);
				}

				$new = wp_insert_term( $name, $taxonomy );

				if ( is_wp_error( $new ) ) {
					return $new;
				}

				$term_ids[] = (int) $new['term_id'];
				$created[]  = $name;
				continue;
			}

			$term_ids[] = (int) $term->term_id;
		}

		$result = wp_set_object_terms( $post->ID, $term_ids, $taxonomy, ! empty( $input['append'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );

		return array(
			'post_id'  => (int) $post->ID,
			'taxonomy' => $taxonomy,
			'before'   => array_values( $before ),
			'after'    => is_wp_error( $after ) ? array() : array_values( $after ),
			'created'  => $created,
		);
	}

	/**
	 * Read the taxonomy terms on one post.
	 *
	 * The read half of execute_set_post_terms. Returns term_id and slug rather
	 * than names alone, because the slug is what a theme matches on and the
	 * name is what a human typed; when those two disagree, nothing renders and
	 * the names look correct.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_post_terms( $input = array() ) {
		$post = get_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );

		if ( ! $post ) {
			return new WP_Error( 'fdj_post_not_found', 'No post found with that ID.' );
		}

		$requested = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';

		if ( '' !== $requested ) {
			if ( ! taxonomy_exists( $requested ) ) {
				return new WP_Error(
					'fdj_taxonomy_not_found',
					sprintf( 'No taxonomy named "%s" is registered on this site.', $requested )
				);
			}

			$taxonomies = array( $requested );
		} else {
			$taxonomies = get_object_taxonomies( $post->post_type );
		}

		$out = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$rows = array();

			foreach ( $terms as $term ) {
				$rows[] = array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'parent'  => (int) $term->parent,
					'count'   => (int) $term->count,
				);
			}

			$out[ $taxonomy ] = $rows;
		}

		return array(
			'post_id'    => $post->ID,
			'post_type'  => $post->post_type,
			'taxonomies' => $out,
		);
	}

	/**
	 * List the terms in one taxonomy.
	 *
	 * hide_empty defaults to false on purpose: a term created as part of a build
	 * is legitimately empty until content is assigned to it, and hiding it makes
	 * a term that exists look like a term that does not.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_terms( $input = array() ) {
		$taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error(
				'fdj_taxonomy_not_found',
				sprintf( 'No taxonomy named "%s" is registered on this site.', $taxonomy )
			);
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 100;
		$per_page = max( 1, min( 200, $per_page ) );

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => ! empty( $input['hide_empty'] ),
			'number'     => $per_page,
		);

		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = (string) $input['search'];
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$results = array();

		foreach ( $terms as $term ) {
			$results[] = array(
				'term_id'     => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'parent'      => (int) $term->parent,
				'count'       => (int) $term->count,
				'description' => $term->description,
			);
		}

		return $results;
	}

	/**
	 * List every registered post type.
	 *
	 * Exists so that "this post type does not exist" and "this post type is
	 * empty" stop being the same answer. Includes private types, which is the
	 * whole point on a page builder site: the parts worth reaching are the ones
	 * with no admin list screen.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute_list_post_types( $input = array() ) {
		$search      = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$public_only = ! empty( $input['public_only'] );

		$objects = get_post_types( array(), 'objects' );
		$results = array();

		foreach ( $objects as $name => $object ) {
			if ( $public_only && empty( $object->public ) ) {
				continue;
			}

			$label = isset( $object->labels->name ) ? (string) $object->labels->name : (string) $name;

			if ( '' !== $search
				&& false === strpos( strtolower( (string) $name ), $search )
				&& false === strpos( strtolower( $label ), $search ) ) {
				continue;
			}

			$counts = array();

			foreach ( (array) wp_count_posts( $name ) as $status => $total ) {
				if ( (int) $total > 0 ) {
					$counts[ $status ] = (int) $total;
				}
			}

			$results[] = array(
				'name'       => (string) $name,
				'label'      => $label,
				'public'     => (bool) $object->public,
				'show_ui'    => (bool) $object->show_ui,
				'taxonomies' => array_values( get_object_taxonomies( $name ) ),
				'counts'     => $counts,
			);
		}

		return $results;
	}

	/**
	 * Create a media library attachment from base64 file content.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_upload_media_data( $input = array() ) {
		$filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
		$b64      = isset( $input['content'] ) ? (string) $input['content'] : '';

		if ( '' === $filename ) {
			return new WP_Error( 'fdj_missing_filename', 'filename is required, including its extension.' );
		}

		if ( '' === $b64 ) {
			return new WP_Error( 'fdj_missing_content', 'content is required: the base64-encoded file.' );
		}

		// Tolerate a data: URI prefix and stray whitespace from transport.
		$b64 = preg_replace( '#^data:[^;]*;base64,#', '', trim( $b64 ) );
		$bin = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- this is a file transport, not obfuscation.

		if ( false === $bin || '' === $bin ) {
			return new WP_Error( 'fdj_bad_base64', 'content is not valid base64.' );
		}

		$check = wp_check_filetype( $filename );

		if ( empty( $check['type'] ) ) {
			return new WP_Error(
				'fdj_disallowed_filetype',
				sprintf( 'WordPress does not allow uploads with the extension on "%s".', $filename )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( $filename, null, $bin );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'fdj_upload_failed', (string) $upload['error'] );
		}

		$title = isset( $input['title'] ) && '' !== $input['title']
			? sanitize_text_field( (string) $input['title'] )
			: preg_replace( '/\.[^.]+$/', '', $filename );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $check['type'],
				'post_title'     => $title,
				'post_excerpt'   => isset( $input['caption'] ) ? sanitize_text_field( (string) $input['caption'] ) : '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $upload['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		if ( ! empty( $input['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt_text'] ) );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'bytes'         => strlen( $bin ),
		);
	}

	/**
	 * Create or update a nav menu, its items, and its theme location.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_manage_menu( $input = array() ) {
		$menu_name = isset( $input['menu_name'] ) ? sanitize_text_field( (string) $input['menu_name'] ) : '';

		if ( '' === $menu_name ) {
			return new WP_Error( 'fdj_missing_menu_name', 'menu_name is required.' );
		}

		$location = isset( $input['location'] ) ? (string) $input['location'] : '';

		if ( '' !== $location ) {
			$registered = get_registered_nav_menus();

			if ( ! isset( $registered[ $location ] ) ) {
				return new WP_Error(
					'fdj_location_not_registered',
					sprintf(
						'Refused: "%s" is not a nav menu location registered by this theme (%s). Nothing was written.',
						$location,
						implode( ', ', array_keys( $registered ) )
					)
				);
			}
		}

		$dry_run = ! empty( $input['dry_run'] );
		$menu    = wp_get_nav_menu_object( $menu_name );
		$created = false;

		if ( ! $menu ) {
			if ( isset( $input['create_if_missing'] ) && empty( $input['create_if_missing'] ) ) {
				return new WP_Error( 'fdj_menu_not_found', sprintf( 'No menu named "%s" exists and create_if_missing is false.', $menu_name ) );
			}

			$created = true;

			if ( ! $dry_run ) {
				$menu_id = wp_create_nav_menu( $menu_name );

				if ( is_wp_error( $menu_id ) ) {
					return $menu_id;
				}

				$menu = wp_get_nav_menu_object( (int) $menu_id );
			}
		}

		$menu_id = $menu ? (int) $menu->term_id : 0;
		$items   = isset( $input['items'] ) && is_array( $input['items'] ) ? array_values( $input['items'] ) : null;
		$report  = array();

		if ( null !== $items ) {

			// Validate every item before touching anything, so a bad item at
			// position 5 does not leave the menu half rebuilt.
			foreach ( $items as $i => $item ) {
				$item = (array) $item;

				if ( empty( $item['title'] ) ) {
					return new WP_Error( 'fdj_item_missing_title', sprintf( 'Item %d has no title.', $i + 1 ) );
				}

				if ( empty( $item['page_id'] ) && empty( $item['url'] ) ) {
					return new WP_Error(
						'fdj_item_missing_target',
						sprintf( 'Item %d ("%s") needs either page_id or url.', $i + 1, $item['title'] )
					);
				}

				if ( ! empty( $item['page_id'] ) && ! get_post( (int) $item['page_id'] ) ) {
					return new WP_Error(
						'fdj_item_page_not_found',
						sprintf( 'Item %d ("%s") points at page_id %d, which does not exist.', $i + 1, $item['title'], (int) $item['page_id'] )
					);
				}

				if ( ! empty( $item['parent'] ) ) {
					$parent = (int) $item['parent'];

					if ( $parent < 1 || $parent > count( $items ) ) {
						return new WP_Error(
							'fdj_item_bad_parent',
							sprintf( 'Item %d ("%s") names parent %d, which is outside the items array.', $i + 1, $item['title'], $parent )
						);
					}

					if ( $parent >= $i + 1 ) {
						return new WP_Error(
							'fdj_item_forward_parent',
							sprintf( 'Item %d ("%s") names parent %d, which is not earlier in the array. A parent must be listed before its children.', $i + 1, $item['title'], $parent )
						);
					}
				}
			}

			if ( ! $dry_run && $menu_id ) {
				foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $existing ) {
					wp_delete_post( (int) $existing->ID, true );
				}
			}

			$db_ids = array();

			foreach ( $items as $i => $item ) {
				$item      = (array) $item;
				$parent_no = ! empty( $item['parent'] ) ? (int) $item['parent'] : 0;
				$parent_id = ( $parent_no && isset( $db_ids[ $parent_no - 1 ] ) ) ? $db_ids[ $parent_no - 1 ] : 0;

				$args = array(
					'menu-item-title'     => sanitize_text_field( (string) $item['title'] ),
					'menu-item-status'    => 'publish',
					'menu-item-parent-id' => $parent_id,
					'menu-item-position'  => $i + 1,
				);

				if ( ! empty( $item['target'] ) ) {
					$args['menu-item-target'] = ( '_blank' === $item['target'] ) ? '_blank' : '';
				}

				if ( ! empty( $item['page_id'] ) ) {
					$page                        = get_post( (int) $item['page_id'] );
					$args['menu-item-type']      = 'post_type';
					$args['menu-item-object']    = $page->post_type;
					$args['menu-item-object-id'] = (int) $page->ID;
				} else {
					$args['menu-item-type'] = 'custom';
					$args['menu-item-url']  = esc_url_raw( (string) $item['url'] );
				}

				if ( ! $dry_run ) {
					$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );

					if ( is_wp_error( $item_id ) ) {
						return $item_id;
					}

					$db_ids[ $i ] = (int) $item_id;
				} else {
					$db_ids[ $i ] = 0;
				}

				$report[] = array(
					'position' => $i + 1,
					'title'    => $args['menu-item-title'],
					'type'     => $args['menu-item-type'],
					'target'   => ! empty( $item['page_id'] ) ? (int) $item['page_id'] : (string) $item['url'],
					'parent'   => $parent_no,
				);
			}
		}

		if ( '' !== $location && ! $dry_run && $menu_id ) {
			$assigned              = get_theme_mod( 'nav_menu_locations', array() );
			$assigned[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $assigned );
		}

		return array(
			'menu_id'      => $menu_id,
			'menu_name'    => $menu_name,
			'created_menu' => $created,
			'items'        => $report,
			'location'     => $location,
			'dry_run'      => $dry_run,
		);
	}

	/**
	 * Regenerate Avada's compiled CSS and clear its caches.
	 *
	 * Avada exposes several cache resets across versions and no single one of
	 * them is guaranteed present, so each is probed and the outcome reported
	 * rather than assumed. A silent no-op here would recreate exactly the bug
	 * this ability exists to fix.
	 *
	 * @return array
	 */
	public static function execute_avada_reset_caches() {
		$ran     = array();
		$skipped = array();

		/*
		 * Each reset is attempted independently, and defensively.
		 *
		 * Avada moves these between plain functions, static methods and
		 * instance methods across versions, and method_exists() is true for a
		 * non-static method too, so a naive static call fatals. Confirmed on
		 * Avada 7.16, where Fusion_Dynamic_CSS::reset_all_caches() threw and
		 * took the whole ability with it after the first reset had already
		 * run. A half-finished cache reset is worse than none, because it can
		 * leave the compiled CSS purged and never regenerated.
		 */
		$targets = array(
			'fusion_reset_all_caches()'              => 'fusion_reset_all_caches',
			'Fusion_Dynamic_CSS::reset_all_caches()' => array( 'Fusion_Dynamic_CSS', 'reset_all_caches' ),
			'Fusion_Cache::reset_all_caches()'       => array( 'Fusion_Cache', 'reset_all_caches' ),
		);

		foreach ( $targets as $label => $target ) {
			$outcome = self::call_defensively( $target );

			if ( true === $outcome ) {
				$ran[] = $label;
			} else {
				$skipped[] = $label . ' - ' . $outcome;
			}
		}

		/*
		 * Avada keys its compiled stylesheet off this timestamp, so bumping it
		 * invalidates every cached sheet even when none of the resets above
		 * were reachable. This is the one step that always works.
		 */
		update_option( 'fusion_dynamic_css_time', time() );
		$ran[] = 'fusion_dynamic_css_time bumped';

		wp_cache_flush();
		$ran[] = 'wp_cache_flush()';

		return array(
			'ran'     => $ran,
			'skipped' => $skipped,
		);
	}

	/**
	 * Call a function or class method without assuming its shape.
	 *
	 * @param string|array $target Function name, or array( class, method ).
	 * @return true|string True on success, otherwise why it was skipped.
	 */
	private static function call_defensively( $target ) {
		try {
			if ( is_string( $target ) ) {
				if ( ! function_exists( $target ) ) {
					return 'not defined';
				}

				$target();

				return true;
			}

			list( $class, $method ) = $target;

			if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
				return 'not available';
			}

			$reflected = new ReflectionMethod( $class, $method );

			if ( ! $reflected->isPublic() ) {
				return 'not public';
			}

			if ( $reflected->isStatic() ) {
				$class::$method();

				return true;
			}

			$constructor = ( new ReflectionClass( $class ) )->getConstructor();

			if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
				return 'instance method needing constructor arguments';
			}

			$instance = new $class();
			$instance->$method();

			return true;
		} catch ( Throwable $e ) {
			return 'threw: ' . $e->getMessage();
		}
	}

}
