<?php
/**
 * User abilities.
 *
 * Accounts are the one thing a directory-style build cannot do without and the
 * one thing this plugin had no way to touch. A GeoDirectory listing, a
 * membership page, an author archive: each of them is owned by a WordPress
 * user, and until now creating that user was the single manual step that kept
 * an otherwise complete build from running end to end.
 *
 * The risk is obvious, so the surface is deliberately narrow:
 *
 * - There is no password parameter anywhere in this file. A new account gets a
 *   long random password that is generated here, never returned, and never
 *   logged. The user sets their own via the link WordPress emails them. That
 *   is not a convenience, it is the point: a password that passes through an
 *   agent's context has been disclosed, whatever happens to it afterwards.
 * - Roles are an allowlist (see ALLOWED_ROLES). Editor and administrator are
 *   not on it and cannot be reached by passing a different string, so no path
 *   through these abilities can mint an account that outranks a subscriber by
 *   much. Changing an existing user's role is not possible here at all.
 * - Nothing deletes a user, and nothing can change an existing account's email
 *   address, the two operations that turn account creation into account
 *   takeover.
 * - fdj/create-user is idempotent by email. Re-running a batch returns the
 *   account that already exists rather than erroring or making a duplicate,
 *   because the realistic failure mode is a half-finished import being run
 *   again, not a fresh site.
 *
 * Like every write in this plugin these are off until someone enables them on
 * the settings screen.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers user abilities with the WordPress Abilities API.
 */
class FDJ_MCP_Users {

	/**
	 * Roles fdj/create-user is allowed to assign.
	 *
	 * Everything here is at or below "can write their own posts". Editor and
	 * administrator are absent on purpose: an ability that can create an admin
	 * is a privilege-escalation primitive, and no build task needs one.
	 */
	const ALLOWED_ROLES = array( 'subscriber', 'contributor', 'author' );

	/** Hard ceiling on users returned by one fdj/list-users call. */
	const MAX_RESULTS = 100;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function get_definitions() {

		return array(

			/* ---------------------------------------------------------- READ */

			'fdj/list-users' => array(
				'is_write'            => false,
				'label'               => 'List Users',
				'description'         => 'Search or list WordPress user accounts by name, email, login, or role. Use this before fdj/create-user to see whether someone already has an account, which is the usual case for a returning member and the usual cause of a duplicate. Returns the user ID needed to set a post\'s author.',
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
							'description' => 'Matches against login, email, display name, first and last name. Omit to list everyone.',
						),
						'role'   => array(
							'type'        => 'string',
							'description' => 'Restrict to one role slug, e.g. "subscriber". Omit for all roles.',
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'Maximum users to return. Capped at 100.',
							'default'     => 20,
						),
						'offset' => array(
							'type'        => 'integer',
							'description' => 'Skip this many results, for paging through a large site.',
							'default'     => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'users' => array( 'type' => 'array' ),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_list_users' ),
				'permission_callback' => array( __CLASS__, 'can_list_users' ),
			),

			/* --------------------------------------------------------- WRITE */

			'fdj/create-user' => array(
				'is_write'            => true,
				'label'               => 'Create User',
				'description'         => 'Create a WordPress account so a person can own a listing, a profile, or their own posts. Takes no password: one is generated internally, never returned, and with notify left on WordPress emails the person its own "set your password" link, so nobody has to invent, send, or store a password. The role is limited to subscriber, contributor, or author. If an account with this email already exists, nothing is created and that account is returned instead (existing: true), so re-running an import is safe. Run with dry_run first to see the username that would be taken.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'email'        => array(
							'type'        => 'string',
							'description' => 'The person\'s email address. This is the identity: an existing account with this address is returned rather than duplicated.',
						),
						'username'     => array(
							'type'        => 'string',
							'description' => 'Optional login name. Derived from the email address when omitted, with a numeric suffix if that login is taken.',
						),
						'first_name'   => array( 'type' => 'string' ),
						'last_name'    => array( 'type' => 'string' ),
						'display_name' => array(
							'type'        => 'string',
							'description' => 'What appears publicly, e.g. on a listing or author archive. Defaults to "First Last" when those are given.',
						),
						'role'         => array(
							'type'        => 'string',
							'description' => 'One of subscriber, contributor, author. Anything else is refused.',
							'enum'        => self::ALLOWED_ROLES,
							'default'     => 'subscriber',
						),
						'notify'       => array(
							'type'        => 'boolean',
							'description' => 'Send WordPress\'s own new-account email, which contains a link to set a password. Leave on unless the person is being told another way; an account created with notify off has no usable password until someone triggers fdj/send-password-reset.',
							'default'     => true,
						),
						'dry_run'      => array(
							'type'        => 'boolean',
							'description' => 'Report what would be created, including the resolved username, without creating anything.',
							'default'     => false,
						),
					),
					'required'   => array( 'email' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'  => array( 'type' => 'integer' ),
						'login'    => array( 'type' => 'string' ),
						'email'    => array( 'type' => 'string' ),
						'role'     => array( 'type' => 'string' ),
						'created'  => array( 'type' => 'boolean' ),
						'existing' => array( 'type' => 'boolean' ),
						'notified' => array( 'type' => 'boolean' ),
						'edit_url' => array( 'type' => 'string' ),
						'dry_run'  => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_create_user' ),
				'permission_callback' => array( __CLASS__, 'can_create_users' ),
			),

			'fdj/send-password-reset' => array(
				'is_write'            => true,
				'label'               => 'Send Password Reset',
				'description'         => 'Trigger WordPress\'s standard password-reset email for one existing account, the same message the "Lost your password?" link sends. Use it for someone whose account predates this process, or who never acted on the original new-account email. The link goes only to that account\'s own address and this ability never sees or sets the password.',
				'category'            => 'site',
				'annotations'         => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user'    => array(
							'type'        => 'string',
							'description' => 'User ID, login, or email address.',
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => 'Resolve the account and report who would be emailed, without sending.',
							'default'     => false,
						),
					),
					'required'   => array( 'user' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array( 'type' => 'integer' ),
						'login'   => array( 'type' => 'string' ),
						'email'   => array( 'type' => 'string' ),
						'sent'    => array( 'type' => 'boolean' ),
						'dry_run' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_send_password_reset' ),
				'permission_callback' => array( __CLASS__, 'can_edit_users' ),
			),

			'fdj/set-post-author' => array(
				'is_write'            => true,
				'label'               => 'Set Post Author',
				'description'         => 'Reassign one post, page, or listing to a different user. This is the other half of fdj/create-user: on a directory site the listing has to belong to the person it describes, or their dashboard shows nothing and plugins that gate editing by ownership refuse to let them near it. Nothing else in this plugin can change post_author.',
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
							'description' => 'The post, page, or custom post type item to reassign.',
						),
						'author'  => array(
							'type'        => 'string',
							'description' => 'The new owner: user ID, login, or email address.',
						),
						'dry_run' => array(
							'type'        => 'boolean',
							'description' => 'Report the change without making it.',
							'default'     => false,
						),
					),
					'required'   => array( 'post_id', 'author' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'        => array( 'type' => 'integer' ),
						'previous_author' => array( 'type' => 'integer' ),
						'author'         => array( 'type' => 'integer' ),
						'author_login'   => array( 'type' => 'string' ),
						'changed'        => array( 'type' => 'boolean' ),
						'dry_run'        => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'execute_set_post_author' ),
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
	 * Can the current user see the user list?
	 *
	 * @return bool
	 */
	public static function can_list_users() {
		return current_user_can( 'list_users' );
	}

	/**
	 * Can the current user create accounts?
	 *
	 * @return bool
	 */
	public static function can_create_users() {
		return current_user_can( 'create_users' );
	}

	/**
	 * Can the current user administer other accounts?
	 *
	 * @return bool
	 */
	public static function can_edit_users() {
		return current_user_can( 'edit_users' );
	}

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

	/* -----------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Resolve a user from an ID, login, or email address.
	 *
	 * Accepting all three is not sloppiness: an import spreadsheet has emails,
	 * a WordPress export has logins, and another ability's output has IDs.
	 * Making the caller convert between them is where mistakes happen.
	 *
	 * @param string|int $value ID, login, or email.
	 * @return WP_User|null
	 */
	private static function resolve_user( $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;

		if ( '' === $value || null === $value ) {
			return null;
		}

		if ( is_numeric( $value ) ) {
			$user = get_user_by( 'id', (int) $value );

			if ( $user ) {
				return $user;
			}
		}

		if ( is_string( $value ) && is_email( $value ) ) {
			$user = get_user_by( 'email', $value );

			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', (string) $value );

		return $user ? $user : null;
	}

	/**
	 * resolve_user() for the other providers.
	 *
	 * fdj/create-post accepts an author by ID, login, or email too, and there
	 * should be exactly one piece of code that decides what those strings mean.
	 *
	 * @param string|int $value ID, login, or email.
	 * @return WP_User|null
	 */
	public static function resolve_user_public( $value ) {
		return self::resolve_user( $value );
	}

	/**
	 * One user, in the shape fdj/list-users returns.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	private static function user_row( $user ) {
		return array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => get_user_meta( $user->ID, 'first_name', true ),
			'last_name'    => get_user_meta( $user->ID, 'last_name', true ),
			'roles'        => array_values( (array) $user->roles ),
			'registered'   => $user->user_registered,
			'edit_url'     => (string) get_edit_user_link( $user->ID ),
		);
	}

	/**
	 * Pick a free login name.
	 *
	 * Derived from the email's local part when nothing was supplied, then
	 * suffixed until it is unique. A collision here is common on a directory
	 * site (two people called j.smith), and failing the whole create over it
	 * would be a poor trade for a name almost nobody ever types.
	 *
	 * @param string $requested Preferred login, may be empty.
	 * @param string $email     Email address to derive from.
	 * @return string
	 */
	private static function available_login( $requested, $email ) {
		$base = sanitize_user( $requested, true );

		if ( '' === $base ) {
			$local = substr( $email, 0, (int) strpos( $email, '@' ) );
			$base  = sanitize_user( $local, true );
		}

		if ( '' === $base ) {
			$base = 'user';
		}

		$base  = strtolower( $base );
		$login = $base;
		$n     = 1;

		while ( username_exists( $login ) ) {
			++$n;
			$login = $base . $n;
		}

		return $login;
	}

	/* -----------------------------------------------------------------
	 * Execute callbacks
	 * ----------------------------------------------------------------- */

	/**
	 * fdj/list-users
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public static function execute_list_users( $input = array() ) {
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 20;
		$limit  = max( 1, min( self::MAX_RESULTS, $limit ) );
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

		$args = array(
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => 'registered',
			'order'   => 'DESC',
		);

		if ( ! empty( $input['search'] ) ) {
			// Wildcards both sides: callers search for a surname or a domain
			// far more often than for an exact login.
			$args['search']         = '*' . trim( (string) $input['search'] ) . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}

		if ( ! empty( $input['role'] ) ) {
			$args['role'] = sanitize_key( $input['role'] );
		}

		$query = new WP_User_Query( $args );
		$users = array();

		foreach ( (array) $query->get_results() as $user ) {
			$users[] = self::user_row( $user );
		}

		return array(
			'users' => $users,
			'total' => (int) $query->get_total(),
		);
	}

	/**
	 * fdj/create-user
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_create_user( $input = array() ) {
		$email = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';

		if ( ! is_email( $email ) ) {
			return new WP_Error(
				'fdj_invalid_email',
				__( 'A valid email address is required to create a user.', 'fdj-wp-abilities' ),
				array( 'status' => 400 )
			);
		}

		$role = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : 'subscriber';

		if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) {
			return new WP_Error(
				'fdj_role_not_allowed',
				sprintf(
					/* translators: 1: requested role, 2: allowed roles. */
					__( 'Role "%1$s" cannot be assigned through this ability. Allowed roles: %2$s.', 'fdj-wp-abilities' ),
					$role,
					implode( ', ', self::ALLOWED_ROLES )
				),
				array( 'status' => 400 )
			);
		}

		// Idempotence by email. A repeated import run should find the account
		// it made last time, not make a second one or stop the batch.
		$existing = get_user_by( 'email', $email );

		if ( $existing ) {
			return array(
				'user_id'  => (int) $existing->ID,
				'login'    => $existing->user_login,
				'email'    => $existing->user_email,
				'role'     => implode( ',', (array) $existing->roles ),
				'created'  => false,
				'existing' => true,
				'notified' => false,
				'edit_url' => (string) get_edit_user_link( $existing->ID ),
				'dry_run'  => ! empty( $input['dry_run'] ),
			);
		}

		$login   = self::available_login( isset( $input['username'] ) ? (string) $input['username'] : '', $email );
		$first   = isset( $input['first_name'] ) ? sanitize_text_field( (string) $input['first_name'] ) : '';
		$last    = isset( $input['last_name'] ) ? sanitize_text_field( (string) $input['last_name'] ) : '';
		$display = isset( $input['display_name'] ) ? sanitize_text_field( (string) $input['display_name'] ) : '';
		$notify  = isset( $input['notify'] ) ? (bool) $input['notify'] : true;

		if ( '' === $display ) {
			$display = trim( $first . ' ' . $last );
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'user_id'  => 0,
				'login'    => $login,
				'email'    => $email,
				'role'     => $role,
				'created'  => false,
				'existing' => false,
				'notified' => false,
				'edit_url' => '',
				'dry_run'  => true,
			);
		}

		/*
		 * The password is generated here and deliberately goes nowhere else:
		 * not into the return value, not into the audit log (which records
		 * input keys only), not into an email. WordPress's own new-user
		 * notification carries a one-time link instead, so this value only has
		 * to be unguessable, never known.
		 */
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => '' !== $display ? $display : $login,
				'nickname'     => '' !== $display ? $display : $login,
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$notified = false;

		if ( $notify && function_exists( 'wp_new_user_notification' ) ) {
			// 'user' sends only the account holder's copy, with the set-password
			// link. 'both' would also mail the site admin on every single
			// creation, which turns a 40-teacher import into 40 emails nobody
			// reads.
			wp_new_user_notification( $user_id, null, 'user' );
			$notified = true;
		}

		return array(
			'user_id'  => (int) $user_id,
			'login'    => $login,
			'email'    => $email,
			'role'     => $role,
			'created'  => true,
			'existing' => false,
			'notified' => $notified,
			'edit_url' => (string) get_edit_user_link( $user_id ),
			'dry_run'  => false,
		);
	}

	/**
	 * fdj/send-password-reset
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_send_password_reset( $input = array() ) {
		$user = self::resolve_user( isset( $input['user'] ) ? $input['user'] : '' );

		if ( ! $user ) {
			return new WP_Error(
				'fdj_user_not_found',
				__( 'No user matches that ID, login, or email address.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'user_id' => (int) $user->ID,
				'login'   => $user->user_login,
				'email'   => $user->user_email,
				'sent'    => false,
				'dry_run' => true,
			);
		}

		if ( ! function_exists( 'retrieve_password' ) ) {
			return new WP_Error(
				'fdj_reset_unavailable',
				__( 'This WordPress install does not expose retrieve_password().', 'fdj-wp-abilities' ),
				array( 'status' => 500 )
			);
		}

		$result = retrieve_password( $user->user_login );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'user_id' => (int) $user->ID,
			'login'   => $user->user_login,
			'email'   => $user->user_email,
			'sent'    => true,
			'dry_run' => false,
		);
	}

	/**
	 * fdj/set-post-author
	 *
	 * @param array $input Ability input.
	 * @return array|WP_Error
	 */
	public static function execute_set_post_author( $input = array() ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error(
				'fdj_post_not_found',
				__( 'No post, page, or listing with that ID.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		$user = self::resolve_user( isset( $input['author'] ) ? $input['author'] : '' );

		if ( ! $user ) {
			return new WP_Error(
				'fdj_user_not_found',
				__( 'No user matches that ID, login, or email address.', 'fdj-wp-abilities' ),
				array( 'status' => 404 )
			);
		}

		$previous = (int) $post->post_author;

		if ( $previous === (int) $user->ID ) {
			return array(
				'post_id'         => $post_id,
				'previous_author' => $previous,
				'author'          => (int) $user->ID,
				'author_login'    => $user->user_login,
				'changed'         => false,
				'dry_run'         => ! empty( $input['dry_run'] ),
			);
		}

		if ( ! empty( $input['dry_run'] ) ) {
			return array(
				'post_id'         => $post_id,
				'previous_author' => $previous,
				'author'          => (int) $user->ID,
				'author_login'    => $user->user_login,
				'changed'         => false,
				'dry_run'         => true,
			);
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => (int) $user->ID,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'post_id'         => $post_id,
			'previous_author' => $previous,
			'author'          => (int) $user->ID,
			'author_login'    => $user->user_login,
			'changed'         => true,
			'dry_run'         => false,
		);
	}
}
