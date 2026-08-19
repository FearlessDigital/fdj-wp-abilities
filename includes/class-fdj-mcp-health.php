<?php
/**
 * Health checks.
 *
 * Every check here exists because it was a real failure that cost real hours.
 * The point is that a red row replaces an afternoon of debugging.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostics for the MCP connection chain.
 */
class FDJ_MCP_Health {

	const PROBE_TRANSIENT = 'fdj_mcp_probe_token';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_probe_route' ) );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'allow_probe_request' ), PHP_INT_MAX );
	}

	/**
	 * Let the probe route answer even when authentication failed.
	 *
	 * rest_authentication_errors is a global gate. Once anything sets an error,
	 * EVERY route returns 401 regardless of its own permission_callback. The probe
	 * deliberately sends a throwaway credential, so on sites where core reports
	 * "invalid_username" for it, the probe could never read its own result and
	 * reported a failure while actually proving success.
	 *
	 * This clears the error for the probe route only, gated on the same
	 * single-use token, and touches nothing else.
	 *
	 * @param WP_Error|null|true $errors Current authentication result.
	 * @return WP_Error|null|true
	 */
	public static function allow_probe_request( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

		if ( false === strpos( $uri, 'fdj-mcp/v1/auth-probe' ) ) {
			return $errors;
		}

		$stored = get_transient( self::PROBE_TRANSIENT );
		$token  = isset( $_GET['token'] ) ? (string) wp_unslash( $_GET['token'] ) : '';

		if ( $stored && $token && hash_equals( (string) $stored, $token ) ) {
			return null;
		}

		return $errors;
	}

	/**
	 * Register the loopback auth probe.
	 *
	 * Public by necessity (it has to be reachable without a cookie) but gated
	 * on a 60 second single-use token, and it returns three booleans and
	 * nothing else. No credential is involved: the loopback request sends a
	 * throwaway Basic header purely to see whether it survives the trip to PHP.
	 */
	public static function register_probe_route() {
		register_rest_route(
			'fdj-mcp/v1',
			'/auth-probe',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_probe' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Probe handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function handle_probe( $request ) {
		$stored = get_transient( self::PROBE_TRANSIENT );
		$token  = (string) $request->get_param( 'token' );

		if ( ! $stored || ! hash_equals( (string) $stored, $token ) ) {
			return new WP_Error( 'fdj_bad_probe_token', 'Invalid or expired probe token.', array( 'status' => 403 ) );
		}

		delete_transient( self::PROBE_TRANSIENT );

		$pre = isset( $GLOBALS['fdj_mcp_auth_pre'] ) ? (array) $GLOBALS['fdj_mcp_auth_pre'] : array();

		return array(
			'http_authorization' => ! empty( $_SERVER['HTTP_AUTHORIZATION'] ),
			'php_auth_user'      => ! empty( $_SERVER['PHP_AUTH_USER'] ),
			'shim_applied'       => ! empty( $GLOBALS['fdj_mcp_shim_applied'] ),
			'pre_php_auth_user'  => ! empty( $pre['php_auth_user'] ),
		);
	}

	/**
	 * Find other code that also populates PHP_AUTH_USER.
	 *
	 * Without this, a leftover mu-plugin doing the same job makes the host look
	 * like it works natively, and the site appears healthy right up until
	 * someone removes the file.
	 *
	 * @return array List of filenames.
	 */
	private static function find_other_shims() {
		$found = array();

		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! is_dir( WPMU_PLUGIN_DIR ) ) {
			return $found;
		}

		foreach ( (array) glob( trailingslashit( WPMU_PLUGIN_DIR ) . '*.php' ) as $file ) {

			// mu-plugins are small by convention; skip anything unexpected.
			if ( ! is_readable( $file ) || filesize( $file ) > 256000 ) {
				continue;
			}

			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( false !== strpos( (string) $contents, 'PHP_AUTH_USER' ) ) {
				$found[] = basename( $file );
			}
		}

		return $found;
	}

	/**
	 * Run the loopback probe.
	 *
	 * @return array{ok:bool,message:string,detail:string}
	 */
	public static function check_basic_auth() {
		$token = wp_generate_password( 32, false );
		set_transient( self::PROBE_TRANSIENT, $token, 60 );

		$response = wp_remote_get(
			add_query_arg( 'token', $token, rest_url( 'fdj-mcp/v1/auth-probe' ) ),
			array(
				'timeout'   => 15,
				'sslverify' => false,
				'headers'   => array(
					// Throwaway values. Never a real credential.
					'Authorization' => 'Basic ' . base64_encode( 'fdj-probe:fdj-probe' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => 'Could not run the loopback test',
				'detail'  => 'The site could not make an HTTP request to itself: ' . $response->get_error_message() . '. This is usually a host firewall rule and does not necessarily mean auth is broken.',
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		/*
		 * A rejection naming the username is positive evidence, not a failure.
		 * WordPress can only say "unknown username" or "invalid application
		 * password" by having read PHP_AUTH_USER, which is the exact thing being
		 * tested. Some sites surface this before the probe route can answer.
		 */
		if ( is_array( $body ) && isset( $body['code'] )
			&& in_array( $body['code'], array( 'invalid_username', 'incorrect_password', 'application_passwords_disabled_for_user' ), true ) ) {

			return array(
				'ok'      => true,
				'message' => 'Working',
				'detail'  => 'WordPress read the credentials and rejected the throwaway ones this test sends, which is the correct response and confirms Basic auth reaches PHP.',
			);
		}

		if ( ! is_array( $body ) || ! isset( $body['php_auth_user'] ) ) {
			return array(
				'ok'      => false,
				'message' => 'Inconclusive',
				'detail'  => 'The loopback test got HTTP ' . $code . ' with no readable result. This does not mean your connection is broken. Confirm directly by generating an Application Password and requesting /wp-json/wp/v2/users/me with it.',
			);
		}

		if ( ! $body['http_authorization'] ) {
			return array(
				'ok'      => false,
				'message' => 'Authorization header is being stripped',
				'detail'  => 'The header never reached PHP. This host removes it upstream. Point your MCP client at the X-Authorization header instead, which this plugin also accepts.',
			);
		}

		if ( ! $body['php_auth_user'] ) {
			return array(
				'ok'      => false,
				'message' => 'Credentials are not reaching WordPress',
				'detail'  => 'The Authorization header arrived but PHP_AUTH_USER was not populated and the shim did not fire. Application Password logins will fail with rest_not_logged_in.',
			);
		}

		if ( ! empty( $body['shim_applied'] ) ) {
			return array(
				'ok'      => true,
				'message' => 'Working, via this plugin\'s compatibility shim',
				'detail'  => 'This host does not populate PHP_AUTH_USER on its own. Application Password logins would fail without this plugin active.',
			);
		}

		/*
		 * PHP_AUTH_USER was already set before this plugin loaded. That is either
		 * the host doing it natively, or something else already doing this job.
		 * Check before claiming the former.
		 */
		$others = self::find_other_shims();

		if ( $others ) {
			return array(
				'ok'      => true,
				'message' => 'Working, but not by this plugin',
				'detail'  => sprintf(
					'PHP_AUTH_USER was already populated before this plugin loaded, and these mu-plugins also reference it: %s. This plugin includes the same shim, so the duplicate is redundant. Remove it and re-check this row to confirm auth still works, and to learn whether the host really is native.',
					implode( ', ', $others )
				),
			);
		}

		return array(
			'ok'      => true,
			'message' => 'Working natively, no shim needed',
			'detail'  => 'PHP_AUTH_USER was populated before this plugin loaded, and no other mu-plugin appears to be doing it, so this host handles it natively.',
		);
	}

	/**
	 * Is the Abilities API present?
	 *
	 * @return array
	 */
	public static function check_abilities_api() {
		$ok = function_exists( 'wp_register_ability' );

		return array(
			'ok'      => $ok,
			'message' => $ok ? 'Available' : 'Missing',
			'detail'  => $ok
				? 'WordPress ' . get_bloginfo( 'version' )
				: 'The Abilities API ships in WordPress 6.9. This site runs ' . get_bloginfo( 'version' ) . '. Nothing here will work until it is updated.',
		);
	}

	/**
	 * Is the MCP Adapter serving an endpoint?
	 *
	 * @return array
	 */
	public static function check_mcp_adapter() {
		$routes = rest_get_server()->get_routes();
		$ok     = isset( $routes['/' . FDJ_MCP_SERVER_PATH ] );

		return array(
			'ok'      => $ok,
			'message' => $ok ? 'Active' : 'Not found',
			'detail'  => $ok
				? fdj_mcp_server_url()
				: 'The MCP Adapter plugin does not appear to be active. Abilities will register but nothing can reach them over MCP. On Pressable, enable it under Tools > WordPress MCP.',
		);
	}

	/**
	 * Are Application Passwords usable?
	 *
	 * @return array
	 */
	public static function check_application_passwords() {
		$available = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();

		return array(
			'ok'      => $available,
			'message' => $available ? 'Enabled' : 'Disabled',
			'detail'  => $available
				? 'Application Passwords can be issued for this site.'
				: 'Application Passwords are disabled, usually by a security plugin or because the site is not served over HTTPS.',
		);
	}

	/**
	 * Which abilities registered, and are they actually visible?
	 *
	 * Registration succeeding is not the same as being exposed. An ability with
	 * the wrong meta registers silently and never appears to any client.
	 *
	 * @return array
	 */
	public static function check_abilities_visible() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array(
				'ok'      => false,
				'message' => 'Cannot check',
				'detail'  => 'The Abilities API is not available.',
			);
		}

		$enabled = 0;
		$visible = 0;
		$missing = array();

		foreach ( FDJ_MCP_Abilities::get_definitions() as $name => $def ) {
			if ( ! fdj_mcp_is_ability_enabled( $name ) ) {
				continue;
			}

			$enabled++;

			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

			if ( ! $ability ) {
				$missing[] = $name . ' (not registered)';
				continue;
			}

			$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();

			$is_visible = ! empty( $meta['show_in_rest'] )
				|| ( isset( $meta['mcp']['public'] ) && $meta['mcp']['public'] );

			if ( $is_visible ) {
				$visible++;
			} else {
				$missing[] = $name . ' (registered but not exposed)';
			}
		}

		$ok = ( $enabled > 0 && $enabled === $visible );

		return array(
			'ok'      => $ok,
			'message' => sprintf( '%d of %d enabled abilities exposed', $visible, $enabled ),
			'detail'  => $missing
				? 'Problems: ' . implode( ', ', $missing )
				: ( $enabled ? 'All enabled abilities are reachable.' : 'No abilities are enabled yet. Turn some on below.' ),
		);
	}

	/**
	 * Every check, in display order.
	 *
	 * @return array<string, array>
	 */
	public static function run_all() {
		return array(
			'WordPress Abilities API' => self::check_abilities_api(),
			'MCP Adapter plugin'      => self::check_mcp_adapter(),
			'Application Passwords'   => self::check_application_passwords(),
			'Basic auth reaching PHP' => self::check_basic_auth(),
			'Abilities exposed'       => self::check_abilities_visible(),
		);
	}
}
