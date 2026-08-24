<?php
/**
 * Gravity Forms ability registration.
 *
 * Gravity Forms has no native Abilities API support of its own (unlike
 * WooCommerce, Jetpack Forms, and Yoast SEO, which register abilities
 * directly and simply show up once active). These abilities exist to give
 * that same visibility to any site running Gravity Forms, wrapping GFAPI
 * rather than duplicating it.
 *
 * Every definition and callback in this file is a no-op unless GFAPI is
 * present, so shipping this to a client site that does not run Gravity
 * Forms changes nothing: get_definitions() returns an empty array, nothing
 * appears in the settings screen, and register() registers nothing.
 *
 * Read-only by design, matching this plugin's default posture. In
 * particular, there is deliberately no "resend notifications" ability here:
 * that action replays a form's configured notification for entries that
 * already ran once, to whatever recipients are configured today, and a
 * mis-scoped bulk resend is exactly the kind of surprise this plugin exists
 * to avoid causing. If that capability is ever needed, it should be its own
 * carefully-scoped write ability, not a shortcut bolted onto a read one.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers Gravity Forms abilities with the WordPress Abilities API.
 */
class FDJ_MCP_GravityForms {

	/** Entry meta keys copied verbatim from a GFAPI entry array; everything else is a field value. */
	const ENTRY_META_KEYS = array(
		'id', 'form_id', 'post_id', 'date_created', 'date_updated', 'is_starred', 'is_read',
		'ip', 'source_url', 'user_agent', 'currency', 'payment_status', 'payment_date',
		'payment_amount', 'payment_method', 'transaction_id', 'is_fulfilled', 'created_by',
		'transaction_type', 'status',
	);

	/** Valid section names for gravityforms/get-form. */
	const FORM_SECTIONS = array( 'fields', 'notifications', 'confirmations', 'settings' );

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Ability definitions. Empty on any site that is not running Gravity Forms.
	 *
	 * @return array<string, array>
	 */
	public static function get_definitions() {
		if ( ! class_exists( 'GFAPI' ) ) {
			return array();
		}

		return array(

			'gravityforms/list-forms' => array(
				'is_write'            => false,
				'requires'            => 'gravityforms',
				'label'               => 'List Gravity Forms Forms',
				'description'         => 'List every Gravity Forms form on the site: ID, title, active/trash state, and field count. Use this to find the right form_id before calling gravityforms/get-form, gravityforms/list-entries, or gravityforms/list-feeds.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'include_inactive' => array(
							'type'        => 'boolean',
							'description' => 'Include forms that are marked inactive. Defaults to true, so an inactive form is not silently hidden while diagnosing a problem.',
							'default'     => true,
						),
						'include_trash'    => array(
							'type'        => 'boolean',
							'description' => 'Include trashed forms. Defaults to false.',
							'default'     => false,
						),
						'include_counts'   => array(
							'type'        => 'boolean',
							'description' => 'Also fetch a live entry count per form. Costs one extra query per form, so it defaults to false; leave it off for a quick listing on a site with many forms.',
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'form_id'     => array( 'type' => 'integer' ),
							'title'       => array( 'type' => 'string' ),
							'is_active'   => array( 'type' => 'boolean' ),
							'is_trash'    => array( 'type' => 'boolean' ),
							'field_count' => array( 'type' => 'integer' ),
							'entry_count' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_forms' ),
				'permission_callback' => array( __CLASS__, 'can_view_gf_forms' ),
			),

			'gravityforms/get-form' => array(
				'is_write'            => false,
				'requires'            => 'gravityforms',
				'label'               => 'Get Gravity Forms Form',
				'description'         => 'Fetch one Gravity Forms form\'s full definition: fields, notifications, confirmations, and top-level settings. Pass "sections" to fetch only what you need. Notifications show exactly who is emailed and on what event, e.g. form_submission; confirmations show what happens after submit, including whether it redirects to another page (type "page"/"redirect") rather than just showing a message, which is usually the first thing to check when a form seems to be forcing users somewhere unexpected.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'  => array(
							'type'        => 'integer',
							'description' => 'The Gravity Forms form ID.',
						),
						'sections' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => self::FORM_SECTIONS,
							),
							'description' => 'Optional. Restrict the response to these sections. Defaults to all four: fields, notifications, confirmations, settings.',
						),
					),
					'required'   => array( 'form_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'       => array( 'type' => 'integer' ),
						'title'         => array( 'type' => 'string' ),
						'fields'        => array( 'type' => 'array' ),
						'notifications' => array( 'type' => 'array' ),
						'confirmations' => array( 'type' => 'array' ),
						'settings'      => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_form' ),
				'permission_callback' => array( __CLASS__, 'can_view_gf_forms' ),
			),

			'gravityforms/list-entries' => array(
				'is_write'            => false,
				'requires'            => 'gravityforms',
				'label'               => 'List Gravity Forms Entries',
				'description'         => 'List or search entries submitted to one form, newest first by default. Field values come back labeled using the form\'s own field labels rather than raw numeric field IDs, so a Name or Address field\'s sub-inputs read as "Address — City" instead of "3.4". Empty field values are omitted to keep long forms readable.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'        => array(
							'type'        => 'integer',
							'description' => 'The Gravity Forms form ID to list entries for.',
						),
						'status'         => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'spam', 'trash' ),
							'description' => 'Restrict to one entry status. Omit for Gravity Forms\' own default, which is active entries only.',
						),
						'date_start'     => array(
							'type'        => 'string',
							'description' => 'Only entries created on or after this date, e.g. "2026-08-01".',
						),
						'date_end'       => array(
							'type'        => 'string',
							'description' => 'Only entries created on or before this date.',
						),
						'sort_direction' => array(
							'type'        => 'string',
							'enum'        => array( 'ASC', 'DESC' ),
							'description' => 'Sort by date created. Defaults to DESC (newest first).',
							'default'     => 'DESC',
						),
						'per_page'       => array(
							'type'        => 'integer',
							'description' => 'Max entries to return. Defaults to 20, capped at 100.',
							'default'     => 20,
						),
						'page'           => array(
							'type'        => 'integer',
							'description' => 'Page number, 1-based. Defaults to 1.',
							'default'     => 1,
						),
					),
					'required'   => array( 'form_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id'     => array( 'type' => 'integer' ),
						'total_count' => array( 'type' => 'integer' ),
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
						'entries'     => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_entries' ),
				'permission_callback' => array( __CLASS__, 'can_view_gf_entries' ),
			),

			'gravityforms/get-entry' => array(
				'is_write'            => false,
				'requires'            => 'gravityforms',
				'label'               => 'Get Gravity Forms Entry',
				'description'         => 'Fetch one Gravity Forms entry by ID, with field values labeled using its form\'s field labels. Includes entry metadata: status, payment info if the form is payment-enabled, source URL, IP, and timestamps.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'entry_id' => array(
							'type'        => 'integer',
							'description' => 'The Gravity Forms entry ID.',
						),
					),
					'required'   => array( 'entry_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'form_id'  => array( 'type' => 'integer' ),
						'status'   => array( 'type' => 'string' ),
						'fields'   => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_get_entry' ),
				'permission_callback' => array( __CLASS__, 'can_view_gf_entries' ),
			),

			'gravityforms/list-feeds' => array(
				'is_write'            => false,
				'requires'            => 'gravityforms',
				'label'               => 'List Gravity Forms Add-On Feeds',
				'description'         => 'List every add-on feed configured on one form, across every add-on, e.g. the WooCommerce order feed, a payment gateway feed, or a third-party integration feed. Each feed reports its addon_slug, whether it is active, and its full meta configuration. This is the connective tissue between a form and whatever it triggers on submission, and is usually the fastest way to see why a form is doing something beyond just collecting field values.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'form_id' => array(
							'type'        => 'integer',
							'description' => 'The Gravity Forms form ID.',
						),
					),
					'required'   => array( 'form_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'form_id' => array( 'type' => 'integer' ),
						'feeds'   => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'feed_id'    => array( 'type' => 'integer' ),
									'addon_slug' => array( 'type' => 'string' ),
									'is_active'  => array( 'type' => 'boolean' ),
									'meta'       => array( 'type' => 'object' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_feeds' ),
				'permission_callback' => array( __CLASS__, 'can_view_gf_forms' ),
			),

			'gravityforms/list-addons' => array(
				'is_write'            => false,
				'requires'            => 'gravityforms',
				'label'               => 'List Gravity Forms Add-Ons',
				'description'         => 'List every Gravity Forms add-on currently active site-wide (slug, title, version), independent of any one form. Pair with gravityforms/list-feeds to see which of these add-ons a specific form actually has configured.',
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
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'       => array( 'type' => 'string' ),
							'title'      => array( 'type' => 'string' ),
							'version'    => array( 'type' => 'string' ),
							'class_name' => array( 'type' => 'string' ),
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_addons' ),
				'permission_callback' => array( __CLASS__, 'can_view_gf_forms' ),
			),
		);
	}

	/**
	 * Register every enabled ability. No-op on a site without Gravity Forms.
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! class_exists( 'GFAPI' ) ) {
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
					// Same explicit show_in_rest + meta.mcp.public pairing as
					// FDJ_MCP_Abilities::register(), for the same reason: meta.public
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
	 * Can the current user view entries?
	 *
	 * Checks Gravity Forms' own capability first, since that is the correct,
	 * least-privilege answer when GF's granular capability system has been
	 * set up. Falls back to manage_options because plenty of real installs
	 * never ran that setup at all, GF's own admin screens still work fine for
	 * an administrator on those sites since GF checks manage_options
	 * internally in that case, and this plugin already trusts manage_options
	 * as its bar for comparably sensitive abilities (see can_manage_options()
	 * in FDJ_MCP_Abilities). Without the fallback, a site like that would
	 * register every gravityforms/* ability and then refuse all of them.
	 *
	 * @return bool
	 */
	public static function can_view_gf_entries() {
		return current_user_can( 'gravityforms_view_entries' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Can the current user view/edit form configuration (fields, notifications,
	 * confirmations, feeds, add-ons)? Same reasoning as can_view_gf_entries().
	 *
	 * @return bool
	 */
	public static function can_view_gf_forms() {
		return current_user_can( 'gravityforms_edit_forms' ) || current_user_can( 'manage_options' );
	}

	/* -----------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Fetch a form by ID, or a WP_Error matching this file's not-found style.
	 *
	 * @param int $form_id Form ID.
	 * @return array|WP_Error
	 */
	private static function get_form_or_error( $form_id ) {
		$form = GFAPI::get_form( (int) $form_id );

		if ( ! $form || is_wp_error( $form ) ) {
			return new WP_Error(
				'fdj_gf_form_not_found',
				sprintf( 'No Gravity Forms form found with ID %d.', (int) $form_id )
			);
		}

		return (array) $form;
	}

	/**
	 * Map every field ID (and, for composite fields, each sub-input ID) to a
	 * human label, so entry output never surfaces a bare "5.3" on its own.
	 *
	 * @param array $form Full form array from GFAPI::get_form().
	 * @return array<string,string>
	 */
	private static function build_field_label_map( $form ) {
		$map = array();

		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $map;
		}

		foreach ( $form['fields'] as $field ) {
			$field    = (array) $field;
			$field_id = isset( $field['id'] ) ? (string) $field['id'] : '';

			if ( '' === $field_id ) {
				continue;
			}

			$label = ( isset( $field['label'] ) && '' !== $field['label'] )
				? $field['label']
				: ( isset( $field['adminLabel'] ) && '' !== $field['adminLabel'] ? $field['adminLabel'] : ( 'Field ' . $field_id ) );

			$map[ $field_id ] = $label;

			if ( empty( $field['inputs'] ) || ! is_array( $field['inputs'] ) ) {
				continue;
			}

			// Composite fields (Name, Address, and similar) store each sub-input
			// under its own dotted ID, e.g. "3.3" for the city part of field 3.
			foreach ( $field['inputs'] as $input ) {
				$input = (array) $input;

				if ( ! isset( $input['id'] ) ) {
					continue;
				}

				$sub_label = ( isset( $input['label'] ) && '' !== $input['label'] ) ? $input['label'] : $label;
				$map[ (string) $input['id'] ] = $label . ' — ' . $sub_label;
			}
		}

		return $map;
	}

	/**
	 * Pull the fixed entry-meta keys off a GFAPI entry array.
	 *
	 * @param array $entry Raw entry array.
	 * @return array
	 */
	private static function extract_entry_meta( $entry ) {
		$meta = array();

		foreach ( self::ENTRY_META_KEYS as $key ) {
			if ( isset( $entry[ $key ] ) ) {
				$meta[ $key ] = $entry[ $key ];
			}
		}

		return $meta;
	}

	/**
	 * Pull field values off a GFAPI entry array, labeled and with blanks
	 * dropped, from whatever isn't one of ENTRY_META_KEYS.
	 *
	 * @param array $entry     Raw entry array.
	 * @param array $label_map From build_field_label_map().
	 * @return array
	 */
	private static function extract_entry_fields( $entry, $label_map ) {
		$fields = array();

		foreach ( $entry as $key => $value ) {
			// Field keys are numeric or dotted-numeric ("5", "5.3"); every
			// meta key is a plain word, so this alone is enough to tell them apart.
			if ( ! preg_match( '/^\d+(?:\.\d+)?$/', (string) $key ) ) {
				continue;
			}

			if ( '' === (string) $value ) {
				continue;
			}

			$fields[] = array(
				'id'    => (string) $key,
				'label' => isset( $label_map[ $key ] ) ? $label_map[ $key ] : ( 'Field ' . $key ),
				'value' => $value,
			);
		}

		return $fields;
	}

	/* -----------------------------------------------------------------
	 * Execute callbacks
	 * ----------------------------------------------------------------- */

	/**
	 * List forms, optionally with a live entry count each.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute_list_forms( $input = array() ) {
		$include_inactive = ! isset( $input['include_inactive'] ) || ! empty( $input['include_inactive'] );
		$include_trash     = ! empty( $input['include_trash'] );
		$include_counts    = ! empty( $input['include_counts'] );

		// GFAPI::get_forms() takes a strict active/inactive bool rather than a
		// tri-state filter, so "both" means calling it twice and merging by ID.
		$forms = (array) GFAPI::get_forms( true, $include_trash );

		if ( $include_inactive ) {
			$forms = array_merge( $forms, (array) GFAPI::get_forms( false, $include_trash ) );
		}

		$seen    = array();
		$results = array();

		foreach ( $forms as $form ) {
			$form = (array) $form;
			$id   = isset( $form['id'] ) ? (int) $form['id'] : 0;

			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$row = array(
				'form_id'     => $id,
				'title'       => isset( $form['title'] ) ? $form['title'] : '',
				'is_active'   => ! empty( $form['is_active'] ),
				'is_trash'    => ! empty( $form['is_trash'] ),
				'field_count' => ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) ? count( $form['fields'] ) : 0,
			);

			if ( $include_counts && method_exists( 'GFAPI', 'count_entries' ) ) {
				$row['entry_count'] = (int) GFAPI::count_entries( $id, array() );
			}

			$results[] = $row;
		}

		usort(
			$results,
			function ( $a, $b ) {
				return $a['form_id'] <=> $b['form_id'];
			}
		);

		return $results;
	}

	/**
	 * Fetch one form, optionally narrowed to specific sections.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_form( $input = array() ) {
		$form = self::get_form_or_error( isset( $input['form_id'] ) ? $input['form_id'] : 0 );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$requested = ( isset( $input['sections'] ) && is_array( $input['sections'] ) && $input['sections'] )
			? array_intersect( self::FORM_SECTIONS, array_map( 'sanitize_key', $input['sections'] ) )
			: self::FORM_SECTIONS;

		$out = array(
			'form_id' => (int) $form['id'],
			'title'   => isset( $form['title'] ) ? $form['title'] : '',
		);

		if ( in_array( 'fields', $requested, true ) ) {
			$out['fields'] = isset( $form['fields'] ) ? $form['fields'] : array();
		}

		if ( in_array( 'notifications', $requested, true ) ) {
			$out['notifications'] = isset( $form['notifications'] ) ? array_values( (array) $form['notifications'] ) : array();
		}

		if ( in_array( 'confirmations', $requested, true ) ) {
			$out['confirmations'] = isset( $form['confirmations'] ) ? array_values( (array) $form['confirmations'] ) : array();
		}

		if ( in_array( 'settings', $requested, true ) ) {
			// Everything that isn't one of the three structured sections above:
			// labelPlacement, is_active, date_created, and similar top-level keys.
			$out['settings'] = array_diff_key( $form, array_flip( array( 'fields', 'notifications', 'confirmations' ) ) );
		}

		return $out;
	}

	/**
	 * List/search entries for one form.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_entries( $input = array() ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		$form    = self::get_form_or_error( $form_id );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		$search_criteria = array();

		if ( ! empty( $input['status'] ) && in_array( $input['status'], array( 'active', 'spam', 'trash' ), true ) ) {
			$search_criteria['status'] = $input['status'];
		}

		if ( ! empty( $input['date_start'] ) ) {
			$search_criteria['start_date'] = (string) $input['date_start'];
		}

		if ( ! empty( $input['date_end'] ) ) {
			$search_criteria['end_date'] = (string) $input['date_end'];
		}

		$sorting = array(
			'key'       => 'date_created',
			'direction' => ( isset( $input['sort_direction'] ) && 'ASC' === strtoupper( (string) $input['sort_direction'] ) ) ? 'ASC' : 'DESC',
		);

		$paging = array(
			'offset'    => ( $page - 1 ) * $per_page,
			'page_size' => $per_page,
		);

		$total_count = 0;
		$entries     = GFAPI::get_entries( array( $form_id ), $search_criteria, $sorting, $paging, $total_count );

		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$label_map = self::build_field_label_map( $form );
		$results   = array();

		foreach ( (array) $entries as $entry ) {
			$entry          = (array) $entry;
			$row            = self::extract_entry_meta( $entry );
			$row['fields']  = self::extract_entry_fields( $entry, $label_map );
			$results[]      = $row;
		}

		return array(
			'form_id'     => $form_id,
			'total_count' => (int) $total_count,
			'page'        => $page,
			'per_page'    => $per_page,
			'entries'     => $results,
		);
	}

	/**
	 * Fetch one entry by ID.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_get_entry( $input = array() ) {
		$entry_id = isset( $input['entry_id'] ) ? (int) $input['entry_id'] : 0;
		$entry    = GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) ) {
			return new WP_Error( 'fdj_gf_entry_not_found', sprintf( 'No Gravity Forms entry found with ID %d.', $entry_id ) );
		}

		$entry = (array) $entry;

		$label_map = array();

		if ( ! empty( $entry['form_id'] ) ) {
			$form = GFAPI::get_form( (int) $entry['form_id'] );

			if ( $form && ! is_wp_error( $form ) ) {
				$label_map = self::build_field_label_map( (array) $form );
			}
		}

		$row           = self::extract_entry_meta( $entry );
		$row['fields'] = self::extract_entry_fields( $entry, $label_map );

		return $row;
	}

	/**
	 * List every add-on feed configured on one form.
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_list_feeds( $input = array() ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		$form    = self::get_form_or_error( $form_id );

		if ( is_wp_error( $form ) ) {
			return $form;
		}

		if ( ! method_exists( 'GFAPI', 'get_feeds' ) ) {
			return new WP_Error(
				'fdj_gf_feeds_unavailable',
				'This site\'s version of Gravity Forms does not expose GFAPI::get_feeds(). Feeds may still exist and be visible under the form\'s own settings in wp-admin.'
			);
		}

		// Only the two leading params are relied on; every GFAPI::get_feeds()
		// signature since it was introduced accepts (feed_ids, form_id, ...),
		// so omitting the rest and letting GF apply its own defaults sidesteps
		// any doubt about what those later defaults are.
		$feeds = GFAPI::get_feeds( null, $form_id );

		if ( is_wp_error( $feeds ) ) {
			return $feeds;
		}

		$results = array();

		foreach ( (array) $feeds as $feed ) {
			$feed      = (array) $feed;
			$results[] = array(
				'feed_id'    => isset( $feed['id'] ) ? (int) $feed['id'] : 0,
				'addon_slug' => isset( $feed['addon_slug'] ) ? $feed['addon_slug'] : '',
				'is_active'  => ! empty( $feed['is_active'] ),
				'meta'       => isset( $feed['meta'] ) ? $feed['meta'] : array(),
			);
		}

		return array(
			'form_id' => $form_id,
			'feeds'   => $results,
		);
	}

	/**
	 * List active Gravity Forms add-ons, site-wide.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute_list_addons( $input = array() ) {
		if ( ! class_exists( 'GFAddOn' ) || ! method_exists( 'GFAddOn', 'get_registered_addons' ) ) {
			return array();
		}

		$classes = GFAddOn::get_registered_addons();
		$results = array();

		foreach ( (array) $classes as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			// Every add-on in the framework is a singleton reachable through its
			// own get_instance(); this is the same call GF's own init_addons()
			// uses internally to reach a usable object from just the class name.
			$instance = is_callable( array( $class_name, 'get_instance' ) )
				? call_user_func( array( $class_name, 'get_instance' ) )
				: null;

			$slug = ( $instance && method_exists( $instance, 'get_slug' ) ) ? $instance->get_slug() : $class_name;

			if ( $instance && method_exists( $instance, 'get_short_title' ) ) {
				$title = $instance->get_short_title();
			} elseif ( $instance && method_exists( $instance, 'get_name' ) ) {
				$title = $instance->get_name();
			} else {
				$title = $class_name;
			}

			$version = ( $instance && method_exists( $instance, 'get_version' ) ) ? $instance->get_version() : '';

			$results[] = array(
				'slug'       => (string) $slug,
				'title'      => wp_strip_all_tags( (string) $title ),
				'version'    => (string) $version,
				'class_name' => $class_name,
			);
		}

		return $results;
	}
}
