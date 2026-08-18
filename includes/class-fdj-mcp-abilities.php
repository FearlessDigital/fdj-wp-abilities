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

		$where  = 'WHERE post_content LIKE %s AND post_status NOT IN ( %s, %s )';
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
}
