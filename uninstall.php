<?php
/**
 * Uninstall cleanup.
 *
 * Application Passwords are deliberately NOT revoked here. They belong to the
 * user, are visible under Users > Profile, and silently deleting credentials
 * during an uninstall would be a surprising side effect.
 *
 * @package fdj-wp-abilities
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'fdj_mcp_settings' );
delete_option( 'fdj_mcp_audit_log' );
delete_transient( 'fdj_mcp_probe_token' );
