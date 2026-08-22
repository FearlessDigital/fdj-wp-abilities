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

		$query = new WP_Query(
			array(
				'post_type'      => isset( $input['post_type'] ) ? $input['post_type'] : 'page',
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
}
