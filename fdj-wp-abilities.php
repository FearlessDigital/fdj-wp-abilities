<?php
/**
 * Plugin Name:       FDJ WordPress Abilities for MCP
 * Description:       Self-contained MCP toolkit for WordPress. Registers page/post abilities, repairs Application Password auth on nginx/PHP-FPM hosts, and adds one-click connection setup, a health panel, and an audit log. Upload, activate, go.
 * Version:           1.2.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Fearless Digital Journey
 * License:           GPL-2.0-or-later
 * Text Domain:       fdj-wp-abilities
 * Update URI:        https://github.com/FearlessDigital/fdj-wp-abilities
 * GitHub Plugin URI: FearlessDigital/fdj-wp-abilities
 * Primary Branch:    main
 *
 * Updates are delivered by Git Updater (https://git-updater.com/), which must be
 * installed on each site. "Primary Branch" is not optional here: Git Updater
 * defaults to "master" and this repo uses "main", so omitting it produces a 404
 * on every update check. The Version header above must exactly match the git tag
 * of the release, or no update is offered.
 *
 * Requires the "MCP Adapter" plugin (https://github.com/WordPress/mcp-adapter) to be
 * active for abilities to be exposed over MCP. The health panel checks for it.
 *
 * NOTE: this file deliberately does NOT declare strict_types. Ability callbacks are
 * invoked by core with arguments whose shape varies across WP versions, and strict
 * mode turns a harmless mismatch into a fatal TypeError.
 */

defined( 'ABSPATH' ) || exit;

define( 'FDJ_MCP_VERSION', '1.2.1' );
define( 'FDJ_MCP_FILE', __FILE__ );
define( 'FDJ_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FDJ_MCP_OPTION', 'fdj_mcp_settings' );
define( 'FDJ_MCP_AUDIT_OPTION', 'fdj_mcp_audit_log' );
define( 'FDJ_MCP_SERVER_PATH', 'mcp/mcp-adapter-default-server' );

/*
 * ---------------------------------------------------------------------------
 * Basic auth shim.
 * ---------------------------------------------------------------------------
 * This runs at FILE SCOPE on purpose, not on a hook. WordPress core's
 * application-password check reads only $_SERVER['PHP_AUTH_USER'] and
 * ['PHP_AUTH_PW']. Apache with mod_php populates those automatically; some
 * nginx and PHP-FPM setups do not, even while passing the Authorization header
 * through intact. Where that happens the result is a bare "rest_not_logged_in".
 *
 * Do not assume a host needs this. Many populate PHP_AUTH_USER natively, in
 * which case the block below no-ops. The health panel reports which case a
 * given site is in. Note also that a valid username with a WRONG password
 * returns "rest_not_logged_in" too, so that symptom alone proves nothing.
 *
 * Plugin files load during wp-settings.php, well before WordPress resolves the
 * current user for a REST request, so doing this here is early enough.
 */
$GLOBALS['fdj_mcp_shim_applied'] = false;

/*
 * Snapshot the state BEFORE touching anything.
 *
 * This matters for the health panel. If PHP_AUTH_USER is already populated by
 * the time this file loads, that could mean the host does it natively, or it
 * could mean another mu-plugin got there first (mu-plugins load before regular
 * plugins). Those two look identical from here, so record the raw state and let
 * the health check disambiguate rather than guess.
 */
$GLOBALS['fdj_mcp_auth_pre'] = array(
	'http_authorization' => ! empty( $_SERVER['HTTP_AUTHORIZATION'] )
		|| ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
		|| ! empty( $_SERVER['HTTP_X_AUTHORIZATION'] ),
	'php_auth_user'      => ! empty( $_SERVER['PHP_AUTH_USER'] ),
);

if ( empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
	if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	} elseif ( ! empty( $_SERVER['HTTP_X_AUTHORIZATION'] ) ) {
		// Fallback for the rarer hosts that strip Authorization outright.
		$_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['HTTP_X_AUTHORIZATION'];
	}
}

if ( empty( $_SERVER['PHP_AUTH_USER'] )
	&& ! empty( $_SERVER['HTTP_AUTHORIZATION'] )
	&& 0 === stripos( $_SERVER['HTTP_AUTHORIZATION'], 'basic ' ) ) {

	$fdj_decoded = base64_decode( substr( $_SERVER['HTTP_AUTHORIZATION'], 6 ), true );

	if ( false !== $fdj_decoded && false !== strpos( $fdj_decoded, ':' ) ) {
		list( $fdj_user, $fdj_pass ) = explode( ':', $fdj_decoded, 2 );

		$_SERVER['PHP_AUTH_USER'] = $fdj_user;
		$_SERVER['PHP_AUTH_PW']   = $fdj_pass;

		$GLOBALS['fdj_mcp_shim_applied'] = true;

		unset( $fdj_user, $fdj_pass );
	}

	unset( $fdj_decoded );
}

require_once FDJ_MCP_DIR . 'includes/class-fdj-mcp-abilities.php';
require_once FDJ_MCP_DIR . 'includes/class-fdj-mcp-health.php';
require_once FDJ_MCP_DIR . 'includes/class-fdj-mcp-audit.php';
require_once FDJ_MCP_DIR . 'includes/class-fdj-mcp-bundle.php';
require_once FDJ_MCP_DIR . 'includes/class-fdj-mcp-settings.php';

/**
 * Default settings.
 *
 * Writes are OFF by default. A freshly activated plugin on a client site
 * should be able to read and nothing else until someone decides otherwise.
 *
 * @return array
 */
function fdj_mcp_default_settings() {
	return array(
		'enabled_abilities' => array( 'fdj/list-posts', 'fdj/search-content', 'fdj/get-post', 'fdj/list-revisions' ),
		'audit_enabled'     => true,
	);
}

/**
 * Get plugin settings merged over defaults.
 *
 * @return array
 */
function fdj_mcp_get_settings() {
	$stored = get_option( FDJ_MCP_OPTION, array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return wp_parse_args( $stored, fdj_mcp_default_settings() );
}

/**
 * Whether a given ability is enabled in settings.
 *
 * @param string $name Ability name.
 * @return bool
 */
function fdj_mcp_is_ability_enabled( $name ) {
	$settings = fdj_mcp_get_settings();

	return in_array( $name, (array) $settings['enabled_abilities'], true );
}

/**
 * Full URL of the MCP server endpoint for this site.
 *
 * @return string
 */
function fdj_mcp_server_url() {
	return rest_url( FDJ_MCP_SERVER_PATH );
}

FDJ_MCP_Abilities::init();
FDJ_MCP_Health::init();
FDJ_MCP_Audit::init();
FDJ_MCP_Bundle::init();
FDJ_MCP_Settings::init();

register_activation_hook(
	FDJ_MCP_FILE,
	function () {
		if ( false === get_option( FDJ_MCP_OPTION ) ) {
			add_option( FDJ_MCP_OPTION, fdj_mcp_default_settings() );
		}
	}
);
