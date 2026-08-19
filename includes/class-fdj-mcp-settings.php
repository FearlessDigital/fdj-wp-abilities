<?php
/**
 * Admin screen.
 *
 * Design note: this screen never asks for, and never stores, an Application
 * Password. The password is a CLIENT credential. WordPress is the server here
 * and core already validates it per request, so a stored copy would be a
 * standing liability on every client site with no functional benefit.
 *
 * Instead the screen GENERATES one for a chosen user, shows it exactly once,
 * and hands back a ready-to-paste connection command.
 *
 * @package fdj-wp-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools > Claude MCP.
 */
class FDJ_MCP_Settings {

	const PAGE_SLUG = 'fdj-mcp';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FDJ_MCP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'tools.php?page=' . self::PAGE_SLUG );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Set up', 'fdj-wp-abilities' ) . '</a>' );

		return $links;
	}

	/**
	 * Register the menu entry.
	 */
	public static function add_menu() {
		add_management_page(
			__( 'Claude MCP', 'fdj-wp-abilities' ),
			__( 'Claude MCP', 'fdj-wp-abilities' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the whole screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'fdj-wp-abilities' ) );
		}

		$notice        = '';
		$new_password  = '';
		$new_user      = null;

		// Handled inline rather than via a redirect: a generated password must
		// never be persisted, not even briefly in a transient, so it has to be
		// rendered in the same request that created it.
		if ( isset( $_POST['fdj_mcp_action'] ) ) {
			$result = self::handle_post();

			$notice       = isset( $result['notice'] ) ? $result['notice'] : '';
			$new_password = isset( $result['password'] ) ? $result['password'] : '';
			$new_user     = isset( $result['user'] ) ? $result['user'] : null;
		}

		$settings = fdj_mcp_get_settings();
		$checks   = FDJ_MCP_Health::run_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Claude MCP', 'fdj-wp-abilities' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Connect this site to Claude over the Model Context Protocol.', 'fdj-wp-abilities' ); ?>
			</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			self::render_health( $checks );
			self::render_connection( $new_password, $new_user );
			FDJ_MCP_Bundle::render_section();
			self::render_abilities( $settings );
			self::render_audit( $settings );
			?>
		</div>
		<?php
	}

	/**
	 * Route a POST to the right handler.
	 *
	 * @return array
	 */
	private static function handle_post() {
		$action = sanitize_text_field( wp_unslash( $_POST['fdj_mcp_action'] ) );

		if ( ! isset( $_POST['fdj_mcp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fdj_mcp_nonce'] ) ), 'fdj_mcp_' . $action ) ) {
			return array( 'notice' => array( 'type' => 'error', 'text' => __( 'Security check failed. Please try again.', 'fdj-wp-abilities' ) ) );
		}

		switch ( $action ) {
			case 'generate_password':
				return self::handle_generate_password();

			case 'save_abilities':
				return self::handle_save_abilities();

			case 'clear_audit':
				FDJ_MCP_Audit::clear();
				return array( 'notice' => array( 'type' => 'success', 'text' => __( 'Audit log cleared.', 'fdj-wp-abilities' ) ) );
		}

		return array();
	}

	/**
	 * Create an Application Password for the selected user.
	 *
	 * @return array
	 */
	private static function handle_generate_password() {
		$user_id = isset( $_POST['fdj_mcp_user'] ) ? (int) $_POST['fdj_mcp_user'] : get_current_user_id();

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return array( 'notice' => array( 'type' => 'error', 'text' => __( 'You cannot create an Application Password for that user.', 'fdj-wp-abilities' ) ) );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) || ! wp_is_application_passwords_available_for_user( $user_id ) ) {
			return array( 'notice' => array( 'type' => 'error', 'text' => __( 'Application Passwords are not available for that user.', 'fdj-wp-abilities' ) ) );
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name' => 'Claude MCP (' . gmdate( 'Y-m-d H:i' ) . ' UTC)',
			)
		);

		if ( is_wp_error( $created ) ) {
			return array( 'notice' => array( 'type' => 'error', 'text' => $created->get_error_message() ) );
		}

		return array(
			'notice'   => array( 'type' => 'success', 'text' => __( 'Application Password created. Copy it now, it will not be shown again.', 'fdj-wp-abilities' ) ),
			'password' => $created[0],
			'user'     => get_userdata( $user_id ),
		);
	}

	/**
	 * Persist ability toggles.
	 *
	 * @return array
	 */
	private static function handle_save_abilities() {
		$posted = isset( $_POST['enabled_abilities'] ) ? (array) wp_unslash( $_POST['enabled_abilities'] ) : array();
		$known  = array_keys( FDJ_MCP_Abilities::get_definitions() );

		$enabled = array_values( array_intersect( $known, array_map( 'sanitize_text_field', $posted ) ) );

		$settings                      = fdj_mcp_get_settings();
		$settings['enabled_abilities'] = $enabled;
		$settings['audit_enabled']     = ! empty( $_POST['audit_enabled'] );

		update_option( FDJ_MCP_OPTION, $settings );

		return array(
			'notice' => array(
				'type' => 'success',
				'text' => __( 'Saved. Changes take effect on the next request.', 'fdj-wp-abilities' ),
			),
		);
	}

	/* -----------------------------------------------------------------
	 * Sections
	 * ----------------------------------------------------------------- */

	/**
	 * Health panel.
	 *
	 * @param array $checks Results from FDJ_MCP_Health::run_all().
	 */
	private static function render_health( $checks ) {
		?>
		<h2><?php esc_html_e( 'Health', 'fdj-wp-abilities' ); ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<?php foreach ( $checks as $label => $check ) : ?>
				<tr>
					<td style="width:24px">
						<span style="font-size:18px;line-height:1"><?php echo $check['ok'] ? '&#9989;' : '&#10060;'; ?></span>
					</td>
					<th scope="row" style="width:220px"><?php echo esc_html( $label ); ?></th>
					<td style="width:260px"><strong><?php echo esc_html( $check['message'] ); ?></strong></td>
					<td><span class="description"><?php echo esc_html( $check['detail'] ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Connection section.
	 *
	 * @param string  $password Freshly generated password, if any.
	 * @param WP_User $user     User it belongs to.
	 */
	private static function render_connection( $password, $user ) {
		$endpoint = fdj_mcp_server_url();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$name     = 'wp-' . sanitize_title( $host );
		$users    = get_users( array( 'capability' => 'edit_posts', 'number' => 100 ) );
		?>
		<h2><?php esc_html_e( 'Connect', 'fdj-wp-abilities' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'MCP endpoint', 'fdj-wp-abilities' ); ?></th>
				<td><code><?php echo esc_html( $endpoint ); ?></code></td>
			</tr>
		</table>

		<form method="post">
			<?php wp_nonce_field( 'fdj_mcp_generate_password', 'fdj_mcp_nonce' ); ?>
			<input type="hidden" name="fdj_mcp_action" value="generate_password" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="fdj_mcp_user"><?php esc_html_e( 'Connect as', 'fdj-wp-abilities' ); ?></label>
					</th>
					<td>
						<select name="fdj_mcp_user" id="fdj_mcp_user">
							<?php foreach ( $users as $u ) : ?>
								<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $u->ID, get_current_user_id() ); ?>>
									<?php echo esc_html( $u->user_login . ' (' . implode( ', ', $u->roles ) . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Best practice: create a dedicated user with the lowest role that does the job, and connect as that. Claude inherits exactly this user\'s capabilities, so revoking access later means deleting one user rather than untangling permissions.', 'fdj-wp-abilities' ); ?>
						</p>
						<?php submit_button( __( 'Generate Application Password', 'fdj-wp-abilities' ), 'primary', 'submit', false ); ?>
					</td>
				</tr>
			</table>
		</form>

		<?php if ( $password && $user ) : ?>
			<?php
			$command = sprintf(
				'claude mcp add --scope user %s --env WP_API_URL=%s --env WP_API_USERNAME=%s --env WP_API_PASSWORD="%s" -- npx -y @automattic/mcp-wordpress-remote@latest',
				$name,
				$endpoint,
				$user->user_login,
				$password
			);
			?>
			<div class="notice notice-warning" style="max-width:900px;padding:12px">
				<p>
					<strong><?php esc_html_e( 'Copy this now.', 'fdj-wp-abilities' ); ?></strong>
					<?php esc_html_e( 'The password is not stored anywhere and cannot be shown again. If you lose it, generate another and revoke this one under Users > Profile.', 'fdj-wp-abilities' ); ?>
				</p>

				<p><label for="fdj-mcp-cmd"><strong><?php esc_html_e( 'Claude Code', 'fdj-wp-abilities' ); ?></strong></label></p>
				<textarea id="fdj-mcp-cmd" readonly rows="4" style="width:100%;font-family:monospace;font-size:12px"><?php echo esc_textarea( $command ); ?></textarea>
				<p>
					<button type="button" class="button" onclick="fdjMcpCopy('fdj-mcp-cmd', this)"><?php esc_html_e( 'Copy command', 'fdj-wp-abilities' ); ?></button>
				</p>

				<p><label for="fdj-mcp-json"><strong><?php esc_html_e( 'Claude Desktop (config JSON)', 'fdj-wp-abilities' ); ?></strong></label></p>
				<textarea id="fdj-mcp-json" readonly rows="14" style="width:100%;font-family:monospace;font-size:12px"><?php
					echo esc_textarea(
						wp_json_encode(
							array(
								'mcpServers' => array(
									$name => array(
										'command' => 'npx',
										'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
										'env'     => array(
											'WP_API_URL'      => $endpoint,
											'WP_API_USERNAME' => $user->user_login,
											'WP_API_PASSWORD' => $password,
										),
									),
								),
							),
							JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
						)
					);
				?></textarea>
				<p>
					<button type="button" class="button" onclick="fdjMcpCopy('fdj-mcp-json', this)"><?php esc_html_e( 'Copy JSON', 'fdj-wp-abilities' ); ?></button>
				</p>
			</div>

			<script>
			function fdjMcpCopy( id, btn ) {
				var el = document.getElementById( id );
				el.select();
				el.setSelectionRange( 0, 99999 );
				try {
					document.execCommand( 'copy' );
					var original = btn.textContent;
					btn.textContent = <?php echo wp_json_encode( __( 'Copied', 'fdj-wp-abilities' ) ); ?>;
					setTimeout( function () { btn.textContent = original; }, 1500 );
				} catch ( e ) {}
			}
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Ability toggles.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_abilities( $settings ) {
		$enabled = (array) $settings['enabled_abilities'];
		?>
		<h2><?php esc_html_e( 'Abilities', 'fdj-wp-abilities' ); ?></h2>
		<p class="description" style="max-width:900px">
			<?php esc_html_e( 'Only enabled abilities are registered and exposed. Write abilities are off by default. Whatever you enable is still bounded by the connected user\'s WordPress capabilities.', 'fdj-wp-abilities' ); ?>
		</p>

		<form method="post">
			<?php wp_nonce_field( 'fdj_mcp_save_abilities', 'fdj_mcp_nonce' ); ?>
			<input type="hidden" name="fdj_mcp_action" value="save_abilities" />

			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th style="width:60px"><?php esc_html_e( 'On', 'fdj-wp-abilities' ); ?></th>
						<th style="width:220px"><?php esc_html_e( 'Ability', 'fdj-wp-abilities' ); ?></th>
						<th style="width:90px"><?php esc_html_e( 'Type', 'fdj-wp-abilities' ); ?></th>
						<th><?php esc_html_e( 'Description', 'fdj-wp-abilities' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( FDJ_MCP_Abilities::get_definitions() as $ability_name => $def ) : ?>
					<tr>
						<td>
							<input
								type="checkbox"
								name="enabled_abilities[]"
								value="<?php echo esc_attr( $ability_name ); ?>"
								id="ability-<?php echo esc_attr( sanitize_html_class( $ability_name ) ); ?>"
								<?php checked( in_array( $ability_name, $enabled, true ) ); ?>
							/>
						</td>
						<th scope="row">
							<label for="ability-<?php echo esc_attr( sanitize_html_class( $ability_name ) ); ?>">
								<?php echo esc_html( $def['label'] ); ?><br />
								<code style="font-size:11px"><?php echo esc_html( $ability_name ); ?></code>
							</label>
						</th>
						<td>
							<?php if ( $def['is_write'] ) : ?>
								<span style="color:#b32d2e;font-weight:600"><?php esc_html_e( 'Write', 'fdj-wp-abilities' ); ?></span>
							<?php else : ?>
								<span style="color:#2271b1"><?php esc_html_e( 'Read', 'fdj-wp-abilities' ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="description"><?php echo esc_html( $def['description'] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:12px">
				<label>
					<input type="checkbox" name="audit_enabled" value="1" <?php checked( ! empty( $settings['audit_enabled'] ) ); ?> />
					<?php esc_html_e( 'Log every ability invocation', 'fdj-wp-abilities' ); ?>
				</label>
			</p>

			<?php submit_button( __( 'Save', 'fdj-wp-abilities' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Audit log table.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_audit( $settings ) {
		$entries = FDJ_MCP_Audit::get_entries( 25 );
		?>
		<h2><?php esc_html_e( 'Recent activity', 'fdj-wp-abilities' ); ?></h2>

		<?php if ( empty( $settings['audit_enabled'] ) ) : ?>
			<p class="description"><?php esc_html_e( 'Logging is currently off.', 'fdj-wp-abilities' ); ?></p>
		<?php endif; ?>

		<?php if ( ! $entries ) : ?>
			<p class="description"><?php esc_html_e( 'Nothing recorded yet.', 'fdj-wp-abilities' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead>
					<tr>
						<th style="width:170px"><?php esc_html_e( 'When', 'fdj-wp-abilities' ); ?></th>
						<th style="width:200px"><?php esc_html_e( 'Ability', 'fdj-wp-abilities' ); ?></th>
						<th style="width:150px"><?php esc_html_e( 'User', 'fdj-wp-abilities' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Post', 'fdj-wp-abilities' ); ?></th>
						<th><?php esc_html_e( 'Input fields', 'fdj-wp-abilities' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $entries as $e ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $e['time'] ) ); ?></td>
						<td><code><?php echo esc_html( $e['ability'] ); ?></code></td>
						<td><?php echo esc_html( $e['user'] ); ?></td>
						<td>
							<?php if ( ! empty( $e['post_id'] ) ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $e['post_id'] ) ); ?>"><?php echo esc_html( $e['post_id'] ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><span class="description"><?php echo esc_html( implode( ', ', (array) $e['keys'] ) ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" style="margin-top:12px">
				<?php wp_nonce_field( 'fdj_mcp_clear_audit', 'fdj_mcp_nonce' ); ?>
				<input type="hidden" name="fdj_mcp_action" value="clear_audit" />
				<?php submit_button( __( 'Clear log', 'fdj-wp-abilities' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
