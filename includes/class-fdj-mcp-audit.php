<?php
/**
 * Audit log.
 *
 * On a client site, "the AI changed something" is not an acceptable answer.
 * This records what ran, as whom, and when.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records ability invocations.
 */
class FDJ_MCP_Audit {

	const MAX_ENTRIES = 100;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_ability_invoked', array( __CLASS__, 'record' ), 10, 2 );
	}

	/**
	 * Record one invocation.
	 *
	 * Input KEYS are stored, never input values. Page content can be enormous
	 * and may contain material that has no business sitting in wp_options.
	 *
	 * @param string $ability_name Ability that ran.
	 * @param mixed  $input        Input passed to it.
	 */
	public static function record( $ability_name, $input = array() ) {
		$settings = fdj_mcp_get_settings();

		if ( empty( $settings['audit_enabled'] ) ) {
			return;
		}

		// Only log abilities this plugin registers. Other plugins can look
		// after themselves. Checked against the real merged list rather than a
		// hardcoded "fdj/" prefix, since gravityforms/* abilities are ours too.
		if ( ! array_key_exists( (string) $ability_name, fdj_mcp_all_ability_definitions() ) ) {
			return;
		}

		$user = wp_get_current_user();

		$entry = array(
			'time'    => time(),
			'ability' => (string) $ability_name,
			'user'    => $user && $user->ID ? $user->user_login : '(none)',
			'user_id' => $user ? (int) $user->ID : 0,
			'keys'    => is_array( $input ) ? array_keys( $input ) : array(),
			'post_id' => is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : null,
		);

		$log = get_option( FDJ_MCP_AUDIT_OPTION, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_ENTRIES );

		// autoload=no: this is written on every ability call and read only in wp-admin.
		update_option( FDJ_MCP_AUDIT_OPTION, $log, false );
	}

	/**
	 * Read the log, newest first.
	 *
	 * @param int $limit Max entries.
	 * @return array
	 */
	public static function get_entries( $limit = 25 ) {
		$log = get_option( FDJ_MCP_AUDIT_OPTION, array() );

		if ( ! is_array( $log ) ) {
			return array();
		}

		return array_slice( $log, 0, $limit );
	}

	/**
	 * Empty the log.
	 */
	public static function clear() {
		update_option( FDJ_MCP_AUDIT_OPTION, array(), false );
	}
}
