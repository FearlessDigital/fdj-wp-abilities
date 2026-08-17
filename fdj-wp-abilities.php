<?php
/**
 * Plugin Name:       FDJ WordPress Abilities
 * Description:       Registers a starter set of WordPress Abilities (via the Abilities API) so the MCP Adapter's default server can expose page/post read + write to an AI client (Claude). Built for Fearless Digital Journey to prototype AI-assisted site building, including Avada/Fusion Builder pages.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Fearless Digital Journey
 * License:           GPL-2.0-or-later
 * Text Domain:       fdj-wp-abilities
 *
 * IMPORTANT: This plugin only registers abilities. It requires:
 *   1. WordPress 6.9+ (ships the Abilities API in core), and
 *   2. The "MCP Adapter" plugin (https://github.com/WordPress/mcp-adapter) active,
 *      so these abilities are exposed over the default MCP server.
 *
 * Every ability below checks WordPress capabilities via permission_callback,
 * so access is scoped to whatever WordPress user/application password is used
 * to authenticate the MCP connection, same as normal WP permissions.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'fdj_register_abilities' );

/**
 * Register all FDJ abilities.
 */
function fdj_register_abilities(): void {

	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	// ------------------------------------------------------------------
	// 1. Read a single post/page by ID.
	// ------------------------------------------------------------------
	wp_register_ability(
		'fdj/get-post',
		array(
			'label'             => 'Get Post or Page',
			'description'       => 'Fetch a single WordPress post or page by ID, including raw post_content (Avada/Fusion Builder shortcodes included as-is).',
			'category'          => 'site',
			'input_schema'      => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array(
						'type'        => 'integer',
						'description' => 'The post or page ID to fetch.',
					),
				),
				'required'   => array( 'post_id' ),
			),
			'output_schema'     => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'    => array( 'type' => 'integer' ),
					'post_type'  => array( 'type' => 'string' ),
					'title'      => array( 'type' => 'string' ),
					'content'    => array( 'type' => 'string' ),
					'status'     => array( 'type' => 'string' ),
					'edit_url'   => array( 'type' => 'string' ),
					'view_url'   => array( 'type' => 'string' ),
					'modified'   => array( 'type' => 'string' ),
				),
			),
			'execute_callback'  => function ( array $input ) {
				$post = get_post( (int) $input['post_id'] );

				if ( ! $post ) {
					return new WP_Error( 'fdj_not_found', 'No post/page found with that ID.' );
				}

				return array(
					'post_id'   => $post->ID,
					'post_type' => $post->post_type,
					'title'     => get_the_title( $post ),
					'content'   => $post->post_content,
					'status'    => $post->post_status,
					'edit_url'  => get_edit_post_link( $post->ID, 'raw' ),
					'view_url'  => get_permalink( $post->ID ),
					'modified'  => $post->post_modified,
				);
			},
			'permission_callback' => function ( array $input ) {
				return current_user_can( 'edit_post', (int) $input['post_id'] );
			},
			'meta'              => array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'mcp'         => array( 'type' => 'tool' ),
			),
		)
	);

	// ------------------------------------------------------------------
	// 2. Update a post/page's content (and optionally title/status).
	//    This is the one that lets us write Avada/Fusion Builder
	//    shortcodes directly into post_content.
	// ------------------------------------------------------------------
	wp_register_ability(
		'fdj/update-post-content',
		array(
			'label'             => 'Update Post or Page Content',
			'description'       => 'Update the content (and optionally title/status) of an existing WordPress post or page. Content is written as-is to post_content, so raw Avada/Fusion Builder shortcodes are supported.',
			'category'          => 'site',
			'input_schema'      => array(
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
			'output_schema'     => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'status'  => array( 'type' => 'string' ),
					'view_url' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'  => function ( array $input ) {
				$existing = get_post( (int) $input['post_id'] );

				if ( ! $existing ) {
					return new WP_Error( 'fdj_not_found', 'No post/page found with that ID.' );
				}

				$update = array(
					'ID'           => (int) $input['post_id'],
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
					'view_url' => get_permalink( $result ),
				);
			},
			'permission_callback' => function ( array $input ) {
				return current_user_can( 'edit_post', (int) $input['post_id'] );
			},
			'meta'              => array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'mcp'         => array( 'type' => 'tool' ),
			),
		)
	);

	// ------------------------------------------------------------------
	// 3. List posts/pages (search + filter by type/status).
	// ------------------------------------------------------------------
	wp_register_ability(
		'fdj/list-posts',
		array(
			'label'             => 'List Posts or Pages',
			'description'       => 'List/search WordPress posts or pages by type, status, and/or search term. Useful for finding the right post_id before reading or editing.',
			'category'          => 'site',
			'input_schema'      => array(
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
						'description' => 'Max results to return.',
						'default'     => 20,
					),
				),
			),
			'output_schema'     => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'status'  => array( 'type' => 'string' ),
						'view_url' => array( 'type' => 'string' ),
					),
				),
			),
			'execute_callback'  => function ( array $input ) {
				$query = new WP_Query(
					array(
						'post_type'      => $input['post_type'] ?? 'page',
						'post_status'    => $input['status'] ?? 'any',
						's'              => $input['search'] ?? '',
						'posts_per_page' => $input['per_page'] ?? 20,
						'no_found_rows'  => true,
					)
				);

				$results = array();

				foreach ( $query->posts as $post ) {
					$results[] = array(
						'post_id'  => $post->ID,
						'title'    => get_the_title( $post ),
						'status'   => $post->post_status,
						'view_url' => get_permalink( $post->ID ),
					);
				}

				return $results;
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'              => array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'mcp'         => array( 'type' => 'tool' ),
			),
		)
	);

	// ------------------------------------------------------------------
	// 4. Create a new post/page.
	// ------------------------------------------------------------------
	wp_register_ability(
		'fdj/create-post',
		array(
			'label'             => 'Create Post or Page',
			'description'       => 'Create a new WordPress post or page with the given title, content, and type. Content can include raw Avada/Fusion Builder shortcodes.',
			'category'          => 'site',
			'input_schema'      => array(
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
			'output_schema'     => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'  => array( 'type' => 'integer' ),
					'status'   => array( 'type' => 'string' ),
					'view_url' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'  => function ( array $input ) {
				$post_id = wp_insert_post(
					array(
						'post_title'   => $input['title'],
						'post_content' => $input['content'],
						'post_type'    => $input['post_type'] ?? 'page',
						'post_status'  => $input['status'] ?? 'draft',
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				return array(
					'post_id'  => $post_id,
					'status'   => get_post_status( $post_id ),
					'view_url' => get_permalink( $post_id ),
				);
			},
			'permission_callback' => function ( array $input ) {
				$post_type_obj = get_post_type_object( $input['post_type'] ?? 'page' );
				$cap            = $post_type_obj->cap->create_posts ?? 'publish_posts';
				return current_user_can( $cap );
			},
			'meta'              => array(
				'public'      => true,
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'mcp'         => array( 'type' => 'tool' ),
			),
		)
	);
}
