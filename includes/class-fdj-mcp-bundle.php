<?php
/**
 * Claude Desktop extension (.mcpb) generation.
 *
 * An MCP Bundle is a zip holding a manifest.json and a local MCP server.
 * Claude Desktop installs one on double-click, so this turns client onboarding
 * from "edit this JSON config file" into "download, double-click, paste one
 * value".
 *
 * The bundle is generated per site: this site's endpoint URL and the chosen
 * username are baked in as user_config defaults, leaving the Application
 * Password as the only field the person has to fill.
 *
 * The password is deliberately NOT baked in. It could be, and it would remove
 * one step, but then the file itself is a live credential being emailed around.
 * Claude Desktop stores the value they enter in the OS keychain instead.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds and serves .mcpb bundles.
 */
class FDJ_MCP_Bundle {

	const ACTION = 'fdj_mcp_download_bundle';

	/** Manifest spec version this bundle conforms to. */
	const MANIFEST_VERSION = '0.3';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Is bundle generation possible on this host?
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'ZipArchive' ) && is_readable( self::server_path() );
	}

	/**
	 * Path to the bundled bridge.
	 *
	 * @return string
	 */
	private static function server_path() {
		return FDJ_MCP_DIR . 'bundle/server/index.js';
	}

	/**
	 * Machine-readable slug for this site, e.g. "sophere-org".
	 *
	 * @return string
	 */
	public static function site_slug() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return sanitize_title( $host ? $host : 'wordpress' );
	}

	/**
	 * Build the manifest for a given connecting user.
	 *
	 * @param WP_User $user User the connection will authenticate as.
	 * @return array
	 */
	public static function manifest( $user ) {
		$slug = self::site_slug();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'manifest_version' => self::MANIFEST_VERSION,
			'name'             => 'fdj-wp-' . $slug,
			'display_name'     => get_bloginfo( 'name' ) . ' (WordPress)',
			'version'          => FDJ_MCP_VERSION,
			'description'      => 'Read and edit ' . $host . ' with Claude.',
			'long_description' => "Connects Claude to the WordPress site at {$host} through the MCP Adapter.\n\nWhat Claude can do is bounded by two things: the abilities enabled under Tools > Claude MCP on the site, and the WordPress capabilities of the user this connects as. Nothing here bypasses normal WordPress permissions.",
			'author'           => array(
				'name' => 'Fearless Digital Journey',
				'url'  => home_url(),
			),
			'server'           => array(
				'type'        => 'node',
				'entry_point' => 'server/index.js',
				'mcp_config'  => array(
					'command' => 'node',
					'args'    => array( '${__dirname}/server/index.js' ),
					'env'     => array(
						'WP_API_URL'      => '${user_config.site_url}',
						'WP_API_USERNAME' => '${user_config.username}',
						'WP_API_PASSWORD' => '${user_config.app_password}',
					),
				),
			),
			'user_config'      => array(
				'site_url'     => array(
					'type'        => 'string',
					'title'       => 'MCP endpoint',
					'description' => 'Already filled in. Leave as is unless the site has moved.',
					'default'     => fdj_mcp_server_url(),
					'required'    => true,
				),
				'username'     => array(
					'type'        => 'string',
					'title'       => 'WordPress username',
					'description' => 'Already filled in. This is the account Claude acts as.',
					'default'     => $user->user_login,
					'required'    => true,
				),
				'app_password' => array(
					'type'        => 'string',
					'title'       => 'Application Password',
					'description' => 'Paste the password generated on the site under Tools > Claude MCP. Stored in your keychain, not in a file.',
					'sensitive'   => true,
					'required'    => true,
				),
			),
		);
	}

	/**
	 * Serve the bundle as a download.
	 */
	public static function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'fdj-wp-abilities' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		if ( ! self::is_available() ) {
			wp_die(
				esc_html__( 'This site cannot build the extension. The PHP ZipArchive extension is required, and the plugin\'s bundle/server/index.js must be present.', 'fdj-wp-abilities' )
			);
		}

		$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : get_current_user_id();

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You cannot build a connector for that user.', 'fdj-wp-abilities' ), '', array( 'response' => 403 ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			wp_die( esc_html__( 'No such user.', 'fdj-wp-abilities' ) );
		}

		$tmp = wp_tempnam( 'fdj-mcpb' );
		$zip = new ZipArchive();

		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create the bundle archive.', 'fdj-wp-abilities' ) );
		}

		$manifest = wp_json_encode( self::manifest( $user ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$zip->addFromString( 'manifest.json', $manifest );
		$zip->addFile( self::server_path(), 'server/index.js' );
		$zip->close();

		$filename = 'wp-' . self::site_slug() . '.mcpb';

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );

		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $tmp );

		exit;
	}

	/**
	 * Render the extension section on the settings screen.
	 */
	public static function render_section() {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'number'     => 100,
			)
		);
		?>
		<h2><?php esc_html_e( 'Claude Desktop extension', 'fdj-wp-abilities' ); ?></h2>

		<p class="description" style="max-width:900px">
			<?php esc_html_e( 'Downloads a one-click installer for Claude Desktop, with this site\'s endpoint and the chosen username already filled in. Whoever installs it only has to paste the Application Password. Use this instead of hand-editing a JSON config file, particularly for anyone who should not be asked to touch a config file at all.', 'fdj-wp-abilities' ); ?>
		</p>

		<?php if ( ! self::is_available() ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Unavailable on this host: building the extension needs the PHP ZipArchive extension. Everything else on this page still works.', 'fdj-wp-abilities' ); ?>
				</p>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="fdj_mcp_bundle_user"><?php esc_html_e( 'Connect as', 'fdj-wp-abilities' ); ?></label>
					</th>
					<td>
						<select name="user" id="fdj_mcp_bundle_user">
							<?php foreach ( $users as $u ) : ?>
								<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $u->ID, get_current_user_id() ); ?>>
									<?php echo esc_html( $u->user_login . ' (' . implode( ', ', $u->roles ) . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Download connector (.mcpb)', 'fdj-wp-abilities' ), 'secondary', '', false ); ?>
						<p class="description">
							<?php esc_html_e( 'Generate the Application Password above first, then download this. Install by double-clicking the file, or via Claude Desktop Settings > Extensions.', 'fdj-wp-abilities' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</form>
		<?php
	}
}
