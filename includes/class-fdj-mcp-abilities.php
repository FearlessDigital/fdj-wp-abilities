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
		return array(

			'fdj/get-post' => array(
				'is_write'            => false,
				'label'               => 'Get Post or Page',
				'description'         => 'Fetch a single WordPress post or page by ID, including raw post_content (page builder shortcodes included as-is).',
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
							'description' => 'The post or page ID to fetch.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer' ),
						'post_type' => array( 'type' => 'string' ),
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'edit_url'  => array( 'type' => 'string' ),
						'view_url'  => array( 'type' => 'string' ),
						'modified'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_post' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

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

			'fdj/update-post-content' => array(
				'is_write'            => true,
				'label'               => 'Update Post or Page Content',
				'description'         => 'Update the content, and optionally the title and status, of an existing WordPress post or page. Content is written as-is to post_content, so raw page builder shortcodes are supported.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The post or page ID to update.',
						),
						'content' => array(
							'type'        => 'string',
							'description' => 'New post_content. Replaces the existing content entirely.',
						),
						'title'   => array(
							'type'        => 'string',
							'description' => 'Optional new post title.',
						),
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'pending', 'publish', 'private' ),
							'description' => 'Optional new post status.',
						),
					),
					'required'   => array( 'post_id', 'content' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'status'   => array( 'type' => 'string' ),
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
						'view_url' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_create_post' ),
				'permission_callback' => array( __CLASS__, 'can_create_post' ),
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
	 * Execute callbacks
	 * ----------------------------------------------------------------- */

	/**
	 * Fetch one post or page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_post( $input = array() ) {
		$post = get_post( (int) $input['post_id'] );

		if ( ! $post ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
		}

		return array(
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'title'     => get_the_title( $post ),
			'content'   => $post->post_content,
			'status'    => $post->post_status,
			'edit_url'  => (string) get_edit_post_link( $post->ID, 'raw' ),
			'view_url'  => (string) get_permalink( $post->ID ),
			'modified'  => $post->post_modified,
		);
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
	 * Update an existing post or page.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_post( $input = array() ) {
		$post_id = (int) $input['post_id'];

		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'fdj_not_found', 'No post or page found with that ID.' );
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

		return array(
			'post_id'  => $result,
			'status'   => get_post_status( $result ),
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
			'post_id'  => $post_id,
			'status'   => get_post_status( $post_id ),
			'view_url' => (string) get_permalink( $post_id ),
		);
	}
}
