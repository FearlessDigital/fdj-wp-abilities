<?php
/**
 * GeoDirectory ability registration.
 *
 * GeoDirectory keeps almost nothing about a listing in post meta. The address,
 * the coordinates, the package, and every custom field live in their own table,
 * `{prefix}geodir_{post_type}_detail`. The practical effect is that a listing
 * read through the ordinary post abilities looks almost empty: `fdj/get-post`
 * returns the title and the description, `fdj/get-post-meta` returns Yoast and
 * theme keys, and the address that is plainly visible on the page is nowhere.
 * These abilities close that gap.
 *
 * Two things about GeoDirectory shaped this file, both learned the hard way on
 * a directory of about 400 teacher listings:
 *
 * 1. GeoDirectory rebuilds a listing's detail row for itself at the END of the
 *    request that created the post. Fields written during that same request are
 *    silently replaced by defaults, and GeoDirectory then re-geocodes from what
 *    is left, which turned a Sarasota address into one in Chico, California.
 *    The page keeps its title and its content, so the result reads as success.
 *    Through MCP each ability call is its own request, so creating the post and
 *    then calling fdj/update-listing is already two requests and safe. Doing
 *    both inside one request is not, and no amount of care inside this file can
 *    fix that for a caller who tries.
 *
 * 2. Because that failure is invisible, fdj/update-listing reads every value
 *    back after writing and returns what is actually stored. A caller should
 *    not have to remember to verify, and "it returned success" should not be
 *    the only evidence that anything happened.
 *
 * Field names are checked against the detail table's own columns rather than a
 * hardcoded list, so a custom field added in GeoDirectory's own settings is
 * writable here the moment it exists, and a typo is refused instead of being
 * accepted and quietly doing nothing.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers GeoDirectory abilities with the WordPress Abilities API.
 */
class FDJ_MCP_GeoDirectory {

	/** Columns nothing may write through these abilities. */
	const PROTECTED_COLUMNS = array( 'post_id' );

	/** Hard ceiling on listings returned by one search. */
	const MAX_RESULTS = 100;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Ability definitions. Empty on any site not running GeoDirectory.
	 *
	 * @return array<string, array>
	 */
	public static function get_definitions() {

		if ( ! self::active() ) {
			return array();
		}

		$post_type = array(
			'type'        => 'string',
			'description' => 'GeoDirectory post type. Defaults to gd_place, which is the one most sites use.',
			'default'     => 'gd_place',
		);

		return array(

			/* ---------------------------------------------------------- READ */

			'fdj/get-listing' => array(
				'is_write'            => false,
				'requires'            => 'geodirectory',
				'label'               => 'Get GeoDirectory Listing',
				'description'         => 'Read one listing\'s stored fields: address, city, region, postcode, coordinates, contact details, package, and every custom field the site has added. None of this is post meta, so no other ability can see it — a listing read with fdj/get-post looks like it has a title and a description and nothing else. Empty fields are omitted unless you ask for them.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array(
							'type'        => 'integer',
							'description' => 'The listing to read.',
						),
						'include_empty'  => array(
							'type'        => 'boolean',
							'description' => 'Include fields that are empty, so you can see what exists but is unset.',
							'default'     => false,
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'    => array( 'type' => 'integer' ),
						'post_type'  => array( 'type' => 'string' ),
						'title'      => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'author'     => array( 'type' => 'integer' ),
						'view_url'   => array( 'type' => 'string' ),
						'fields'     => array( 'type' => 'object' ),
						'categories' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_listing' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/list-listing-fields' => array(
				'is_write'            => false,
				'requires'            => 'geodirectory',
				'label'               => 'List GeoDirectory Listing Fields',
				'description'         => 'List the custom fields defined for a GeoDirectory post type: the name to write, the field type, and the admin label. For a select or multiselect field, pass "field" to get its option list — the values a listing is allowed to hold. Use this before writing a select field, because a value that is not an option is stored and then silently ignored by the site, which looks identical to a value that never saved.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => $post_type,
						'field'     => array(
							'type'        => 'string',
							'description' => 'One field name (htmlvar_name). Returns that field alone, including its full option list. Omit for every field without option lists, which keeps the response small on a site with hundreds of options.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'fields'    => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_listing_fields' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			'fdj/geocode-address' => array(
				'is_write'            => false,
				'requires'            => 'geodirectory',
				'label'               => 'Geocode an Address',
				'description'         => 'Turn a written address into the parts a listing needs: city, region, postcode, country and coordinates. Uses the site\'s own Google Maps key, so the key stays on the server. Prefer this to parsing an address by hand — real address lists are wildly inconsistent ("622 Burnhaven Ln, Wilmington, DE, 19808-2369" next to "5855 Calgary ST, Timnath CO  80547") and a listing with no coordinates never appears on the map. Note the geocoder normalises the street line ("Ln" becomes "Lane"), so keep the street as originally written and take the rest from here.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'address' => array(
							'type'        => 'string',
							'description' => 'The address as written, in one line.',
						),
					),
					'required'   => array( 'address' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'matched'          => array( 'type' => 'boolean' ),
						'formatted'        => array( 'type' => 'string' ),
						'street'           => array( 'type' => 'string' ),
						'city'             => array( 'type' => 'string' ),
						'region'           => array( 'type' => 'string' ),
						'zip'              => array( 'type' => 'string' ),
						'country'          => array( 'type' => 'string' ),
						'latitude'         => array( 'type' => 'number' ),
						'longitude'        => array( 'type' => 'number' ),
						'partial_match'    => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_geocode_address' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),

			/* --------------------------------------------------------- WRITE */

			'fdj/update-listing' => array(
				'is_write'            => true,
				'requires'            => 'geodirectory',
				'label'               => 'Update GeoDirectory Listing Fields',
				'description'         => 'Write a listing\'s fields: address, coordinates, contact details, package, and custom fields. This is the only way to set them — they are not post meta. Only the fields you pass change. Field names are checked against the listing table, so a typo is refused rather than accepted and ignored. Every value is read back after writing and returned, because GeoDirectory can undo a write without reporting an error. IMPORTANT: if you have just created the post, this must be a separate call from the one that created it — GeoDirectory rebuilds the row at the end of the creating request and will overwrite anything written during it. Run with dry_run first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The listing to update.',
						),
						'fields'  => array(
							'type'        => 'object',
							'description' => 'Field name to value, e.g. {"street": "1905 Pheasant Run", "city": "Tupelo", "region": "Mississippi", "zip": "38801", "latitude": 34.2371548, "longitude": -88.7339412}. A multiselect value is a comma-separated string, with no space after the comma.',
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => 'Report what would change, including any refused field names, without writing.',
							'default'     => false,
						),
					),
					'required'   => array( 'post_id', 'fields' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'changed'  => array( 'type' => 'array' ),
						'unchanged' => array( 'type' => 'array' ),
						'refused'  => array( 'type' => 'array' ),
						'stored'   => array( 'type' => 'object' ),
						'verified' => array( 'type' => 'boolean' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_update_listing' ),
				'permission_callback' => array( __CLASS__, 'can_edit_post' ),
			),

			'fdj/add-listing-field-option' => array(
				'is_write'            => true,
				'requires'            => 'geodirectory',
				'label'               => 'Add Options to a Listing Field',
				'description'         => 'Add one or more options to a select or multiselect listing field, for example a newly qualified name that listings now need to choose. Existing options are never removed or reordered, and an option already present is skipped rather than duplicated. Worth knowing: an option list is stored newline-separated while a listing stores its chosen values comma-separated, and writing a value that is not on the list fails silently — the page simply does not show it. Run with dry_run first.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => $post_type,
						'field'     => array(
							'type'        => 'string',
							'description' => 'Field name (htmlvar_name), e.g. accreditation_teacher.',
						),
						'options'   => array(
							'type'        => 'array',
							'description' => 'Options to add.',
							'items'       => array( 'type' => 'string' ),
						),
						'position'  => array(
							'type'        => 'string',
							'description' => 'Where to put new options: "end" (default) or "start". Some lists are kept with the most recent first.',
							'enum'        => array( 'end', 'start' ),
							'default'     => 'end',
						),
						'dry_run'   => array(
							'type'        => 'boolean',
							'description' => 'Report what would be added without writing.',
							'default'     => false,
						),
					),
					'required'   => array( 'field', 'options' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'field'         => array( 'type' => 'string' ),
						'added'         => array( 'type' => 'array' ),
						'already_there' => array( 'type' => 'array' ),
						'count_before'  => array( 'type' => 'integer' ),
						'count_after'   => array( 'type' => 'integer' ),
						'verified'      => array( 'type' => 'boolean' ),
						'dry_run'       => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_add_listing_field_option' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
			),
		);
	}

	/**
	 * Register every enabled ability.
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::active() ) {
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
					// Same explicit pairing as the other providers: meta.public
					// alone is invisible to MCP on WP 6.9/7.0.
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
	 * Can the current user edit the referenced listing?
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
	 * Changing a field's option list is a site-wide setting, not one listing.
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
	 * Is GeoDirectory active here?
	 *
	 * @return bool
	 */
	private static function active() {
		return defined( 'GEODIRECTORY_VERSION' ) || class_exists( 'GeoDirectory' );
	}

	/**
	 * The detail table for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private static function table( $post_type ) {
		global $wpdb;

		return $wpdb->prefix . 'geodir_' . sanitize_key( $post_type ) . '_detail';
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Column names of a detail table.
	 *
	 * Read from the table rather than hardcoded: a custom field added through
	 * GeoDirectory's own settings becomes a column, and a list maintained here
	 * would be wrong the first time someone adds one.
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	private static function columns( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$cols = $wpdb->get_col( 'DESC ' . $table, 0 );

		return is_array( $cols ) ? $cols : array();
	}

	/**
	 * Split a stored option list.
	 *
	 * GeoDirectory stores these newline-separated. Some older fields use commas,
	 * so both are handled, but a list that already contains newlines is never
	 * re-split on commas — an option legitimately containing a comma would be
	 * torn in half.
	 *
	 * @param string $stored Raw option_values.
	 * @return array
	 */
	private static function split_options( $stored ) {
		$stored = (string) $stored;

		if ( '' === trim( $stored ) ) {
			return array();
		}

		$parts = ( false !== strpos( $stored, "\n" ) )
			? preg_split( '/\r\n|\r|\n/', $stored )
			: explode( ',', $stored );

		$parts = array_map( 'trim', (array) $parts );

		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * One custom field row.
	 *
	 * @param string $post_type Post type.
	 * @param string $field     htmlvar_name.
	 * @return object|null
	 */
	private static function field_row( $post_type, $field ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}geodir_custom_fields WHERE post_type = %s AND htmlvar_name = %s",
				$post_type,
				$field
			)
		);
	}

	/* -----------------------------------------------------------------
	 * Execute callbacks
	 * ----------------------------------------------------------------- */

	/**
	 * fdj/get-listing
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_listing( $input = array() ) {
		global $wpdb;

		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'fdj_post_not_found',
				__( 'No post with that ID.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		$table = self::table( $post->post_type );

		if ( ! self::table_exists( $table ) ) {
			return new WP_Error(
				'fdj_not_a_listing',
				sprintf(
					/* translators: %s: post type. */
					__( 'Post type "%s" is not a GeoDirectory listing type.', 'fdj-wp-abilities' ),
					$post->post_type
				),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error(
				'fdj_listing_row_missing',
				__( 'This post has no GeoDirectory row yet. That usually means it was created outside GeoDirectory and has never been saved.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $input['include_empty'] ) ) {
			$row = array_filter(
				$row,
				function ( $v ) {
					return null !== $v && '' !== $v && '0000-00-00' !== $v;
				}
			);
		}

		$categories = array();

		foreach ( (array) get_the_terms( $post_id, $post->post_type . 'category' ) as $term ) {
			if ( $term instanceof WP_Term ) {
				$categories[] = array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
				);
			}
		}

		return array(
			'post_id'    => $post_id,
			'post_type'  => $post->post_type,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'author'     => (int) $post->post_author,
			'view_url'   => (string) get_permalink( $post_id ),
			'fields'     => $row,
			'categories' => $categories,
		);
	}

	/**
	 * fdj/list-listing-fields
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_listing_fields( $input = array() ) {
		global $wpdb;

		$post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'gd_place';

		if ( ! empty( $input['field'] ) ) {
			$row = self::field_row( $post_type, sanitize_key( $input['field'] ) );

			if ( ! $row ) {
				return new WP_Error(
					'fdj_field_not_found',
					__( 'No such field on that post type.', 'fdj-wp-abilities' ),
					array( 'status' => 404 )
				);
			}

			$options = self::split_options( $row->option_values );

			return array(
				'post_type' => $post_type,
				'fields'    => array(
					array(
						'name'         => $row->htmlvar_name,
						'type'         => $row->field_type,
						'label'        => $row->admin_title,
						'option_count' => count( $options ),
						'options'      => $options,
					),
				),
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT htmlvar_name, field_type, admin_title, option_values FROM {$wpdb->prefix}geodir_custom_fields WHERE post_type = %s ORDER BY sort_order ASC",
				$post_type
			)
		);

		$fields = array();

		foreach ( (array) $rows as $row ) {
			$options = self::split_options( $row->option_values );

			// The option list itself is deliberately omitted here. One field on
			// a real site holds 435 options, and returning every list would bury
			// the answer to "what fields exist" under thousands of names.
			$fields[] = array(
				'name'         => $row->htmlvar_name,
				'type'         => $row->field_type,
				'label'        => $row->admin_title,
				'option_count' => count( $options ),
			);
		}

		return array(
			'post_type' => $post_type,
			'fields'    => $fields,
		);
	}

	/**
	 * fdj/geocode-address
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_geocode_address( $input = array() ) {
		$address = trim( (string) $input['address'] );

		if ( '' === $address ) {
			return new WP_Error(
				'fdj_no_address',
				__( 'An address is required.', 'fdj-wp-abilities' ),
				array( 'status' => 400 )
			);
		}

		$key = function_exists( 'geodir_get_option' ) ? geodir_get_option( 'google_maps_api_key' ) : '';

		if ( empty( $key ) ) {
			return new WP_Error(
				'fdj_no_maps_key',
				__( 'This site has no Google Maps API key set in GeoDirectory, so addresses cannot be geocoded here.', 'fdj-wp-abilities' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'address' => rawurlencode( $address ),
					'key'     => $key,
				),
				'https://maps.googleapis.com/maps/api/geocode/json'
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['results'][0] ) ) {
			return array(
				'matched'   => false,
				'formatted' => '',
				'status'    => isset( $body['status'] ) ? $body['status'] : 'UNKNOWN',
			);
		}

		$result = $body['results'][0];
		$parts  = array(
			'street_number'              => '',
			'route'                      => '',
			'subpremise'                 => '',
			'locality'                   => '',
			'administrative_area_level_1' => '',
			'postal_code'                => '',
			'country'                    => '',
		);

		foreach ( (array) $result['address_components'] as $component ) {
			foreach ( (array) $component['types'] as $type ) {
				if ( array_key_exists( $type, $parts ) && '' === $parts[ $type ] ) {
					$parts[ $type ] = $component['long_name'];
				}
			}
		}

		$street = trim( $parts['street_number'] . ' ' . $parts['route'] );

		/*
		 * A subpremise comes back as either "401" or "Unit 401" depending on how
		 * the address was written, so prefixing "Unit" unconditionally produces
		 * "Unit Unit 401".
		 */
		if ( '' !== $parts['subpremise'] ) {
			$unit    = $parts['subpremise'];
			$street .= preg_match( '/^[0-9]/', $unit ) ? ' Unit ' . $unit : ' ' . $unit;
		}

		return array(
			'matched'       => true,
			'formatted'     => isset( $result['formatted_address'] ) ? $result['formatted_address'] : '',
			'street'        => $street,
			'city'          => $parts['locality'],
			'region'        => $parts['administrative_area_level_1'],
			'zip'           => $parts['postal_code'],
			'country'       => $parts['country'],
			'latitude'      => isset( $result['geometry']['location']['lat'] ) ? (float) $result['geometry']['location']['lat'] : null,
			'longitude'     => isset( $result['geometry']['location']['lng'] ) ? (float) $result['geometry']['location']['lng'] : null,
			'partial_match' => ! empty( $result['partial_match'] ),
		);
	}

	/**
	 * fdj/update-listing
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_update_listing( $input = array() ) {
		global $wpdb;

		$post_id = (int) $input['post_id'];
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'fdj_post_not_found',
				__( 'No post with that ID.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		$fields = isset( $input['fields'] ) ? (array) $input['fields'] : array();

		if ( ! $fields ) {
			return new WP_Error(
				'fdj_no_fields',
				__( 'No fields to write.', 'fdj-wp-abilities' ),
				array( 'status' => 400 )
			);
		}

		$table = self::table( $post->post_type );

		if ( ! self::table_exists( $table ) ) {
			return new WP_Error(
				'fdj_not_a_listing',
				sprintf(
					/* translators: %s: post type. */
					__( 'Post type "%s" is not a GeoDirectory listing type.', 'fdj-wp-abilities' ),
					$post->post_type
				),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );

		if ( ! $current ) {
			return new WP_Error(
				'fdj_listing_row_missing',
				__( 'This post has no GeoDirectory row to update.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		$columns   = self::columns( $table );
		$write     = array();
		$changed   = array();
		$unchanged = array();
		$refused   = array();

		foreach ( $fields as $name => $value ) {
			$name = (string) $name;

			if ( in_array( $name, self::PROTECTED_COLUMNS, true ) ) {
				$refused[] = array(
					'field'  => $name,
					'reason' => 'protected',
				);
				continue;
			}

			if ( ! in_array( $name, $columns, true ) ) {
				$refused[] = array(
					'field'  => $name,
					'reason' => 'no such field on this listing type',
				);
				continue;
			}

			if ( is_array( $value ) ) {
				// Multiselect values are stored comma-separated with no space.
				$value = implode( ',', array_map( 'strval', $value ) );
			}

			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			}

			$old = array_key_exists( $name, $current ) ? $current[ $name ] : null;

			// Loose comparison on purpose: everything comes back from MySQL as a
			// string, so 4 and "4" are the same stored value and reporting that
			// as a change would be noise.
			if ( null !== $old && (string) $old === (string) $value ) {
				$unchanged[] = $name;
				continue;
			}

			$write[ $name ] = $value;
			$changed[]      = array(
				'field' => $name,
				'from'  => $old,
				'to'    => $value,
			);
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'post_id'   => $post_id,
				'changed'   => $changed,
				'unchanged' => $unchanged,
				'refused'   => $refused,
				'stored'    => array(),
				'verified'  => false,
				'dry_run'   => true,
			);
		}

		if ( $write ) {
			$result = $wpdb->update( $table, $write, array( 'post_id' => $post_id ) );

			if ( false === $result ) {
				return new WP_Error(
					'fdj_write_failed',
					__( 'The listing row could not be written.', 'fdj-wp-abilities' ),
					array( 'status' => 500 )
				);
			}
		}

		/*
		 * Read back rather than trusting the write. GeoDirectory can rebuild
		 * this row for itself at the end of a request, so a write that reported
		 * success is not proof that the value is there.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$after    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
		$stored   = array();
		$verified = true;

		foreach ( $write as $name => $value ) {
			$stored[ $name ] = isset( $after[ $name ] ) ? $after[ $name ] : null;

			if ( (string) $stored[ $name ] !== (string) $value ) {
				$verified = false;
			}
		}

		return array(
			'post_id'   => $post_id,
			'changed'   => $changed,
			'unchanged' => $unchanged,
			'refused'   => $refused,
			'stored'    => $stored,
			'verified'  => $verified,
			'dry_run'   => false,
		);
	}

	/**
	 * fdj/add-listing-field-option
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_add_listing_field_option( $input = array() ) {
		global $wpdb;

		$post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'gd_place';
		$field     = sanitize_key( $input['field'] );
		$row       = self::field_row( $post_type, $field );

		if ( ! $row ) {
			return new WP_Error(
				'fdj_field_not_found',
				__( 'No such field on that post type.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $row->field_type, array( 'select', 'multiselect', 'radio', 'checkbox' ), true ) ) {
			return new WP_Error(
				'fdj_field_has_no_options',
				sprintf(
					/* translators: %s: field type. */
					__( 'A "%s" field has no option list.', 'fdj-wp-abilities' ),
					$row->field_type
				),
				array( 'status' => 400 )
			);
		}

		$existing = self::split_options( $row->option_values );
		$position = ( isset( $input['position'] ) && 'start' === $input['position'] ) ? 'start' : 'end';

		$add     = array();
		$present = array();

		foreach ( (array) $input['options'] as $option ) {
			$option = trim( (string) $option );

			if ( '' === $option ) {
				continue;
			}

			if ( in_array( $option, $existing, true ) || in_array( $option, $add, true ) ) {
				$present[] = $option;
				continue;
			}

			$add[] = $option;
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'field'         => $field,
				'added'         => $add,
				'already_there' => $present,
				'count_before'  => count( $existing ),
				'count_after'   => count( $existing ) + count( $add ),
				'verified'      => false,
				'dry_run'       => true,
			);
		}

		if ( $add ) {
			$merged = ( 'start' === $position )
				? array_merge( $add, $existing )
				: array_merge( $existing, $add );

			$wpdb->update(
				$wpdb->prefix . 'geodir_custom_fields',
				array( 'option_values' => implode( "\r\n", $merged ) ),
				array(
					'post_type'    => $post_type,
					'htmlvar_name' => $field,
				)
			);
		}

		$after       = self::field_row( $post_type, $field );
		$after_opts  = $after ? self::split_options( $after->option_values ) : array();
		$verified    = true;

		foreach ( $add as $option ) {
			if ( ! in_array( $option, $after_opts, true ) ) {
				$verified = false;
			}
		}

		return array(
			'field'         => $field,
			'added'         => $add,
			'already_there' => $present,
			'count_before'  => count( $existing ),
			'count_after'   => count( $after_opts ),
			'verified'      => $verified,
			'dry_run'       => false,
		);
	}
}
