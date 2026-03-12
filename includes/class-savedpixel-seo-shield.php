<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SavedPixel_Seo_Shield {

	const VERSION     = '1.0';
	const OPTION      = 'savedpixel_seo_shield_settings';
	const LOG_OPTION  = 'savedpixel_seo_shield_log';
	const MENU_SLUG   = 'savedpixel-seo-shield';
	const SEARCH_KEY  = 's';
	const LOG_MAX     = 200;
	const LOG_PAGE_SZ = 200;

	private static $instance = null;

	public static function bootstrap() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate() {
		self::ensure_settings();

		if ( false === get_option( self::LOG_OPTION, false ) ) {
			add_option( self::LOG_OPTION, array(), '', 'no' );
		}
	}

	public static function defaults() {
		return array(
			'block_spam_search_queries'    => 1,
			'spam_search_regex'            => '\\.(jp|cn|ru)$',
			'redirect_search_queries'      => 1,
			'noindex_search_pages'         => 1,
			'enable_search_recaptcha'      => 0,
			'recaptcha_site_key'           => '',
			'recaptcha_secret_key'         => '',
			'strip_tracking_params'        => 1,
			'tracking_params'              => 'utm_source,utm_medium,ref',
			'force_https_redirect'         => 0,
			'enable_rate_limit'            => 1,
			'rate_limit_requests_per_minute' => 10,
			'disable_xmlrpc'               => 1,
			'restrict_anonymous_rest'      => 0,
			'allowed_public_rest_namespaces' => 'wp/v2,oembed/1.0,wc/store',
		);
	}

	public static function ensure_settings() {
		$current  = get_option( self::OPTION, null );
		$changed  = ! is_array( $current );
		$settings = is_array( $current ) ? $current : array();

		foreach ( self::defaults() as $key => $value ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $value;
				$changed          = true;
			}
		}

		$settings = self::sanitize_settings( $settings, false );

		if ( $changed || $settings !== $current ) {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, $settings, '', 'no' );
			} else {
				update_option( self::OPTION, $settings );
			}
		}
	}

	public static function settings() {
		return self::sanitize_settings( get_option( self::OPTION, array() ), false );
	}

	private static function sanitize_settings( $raw, $from_form ) {
		$defaults = self::defaults();
		$raw      = is_array( $raw ) ? $raw : array();

		$booleans = array(
			'block_spam_search_queries',
			'redirect_search_queries',
			'noindex_search_pages',
			'enable_search_recaptcha',
			'strip_tracking_params',
			'force_https_redirect',
			'enable_rate_limit',
			'disable_xmlrpc',
			'restrict_anonymous_rest',
		);

		$settings = array();

		foreach ( $booleans as $key ) {
			$settings[ $key ] = $from_form
				? ( isset( $raw[ $key ] ) ? 1 : 0 )
				: ( ! empty( $raw[ $key ] ) ? 1 : 0 );
		}

		$regex = isset( $raw['spam_search_regex'] ) ? trim( (string) wp_unslash( $raw['spam_search_regex'] ) ) : '';
		$settings['spam_search_regex'] = '' !== $regex ? $regex : $defaults['spam_search_regex'];

		$settings['recaptcha_site_key'] = isset( $raw['recaptcha_site_key'] ) ? trim( sanitize_text_field( wp_unslash( $raw['recaptcha_site_key'] ) ) ) : '';
		$settings['recaptcha_secret_key'] = isset( $raw['recaptcha_secret_key'] ) ? trim( sanitize_text_field( wp_unslash( $raw['recaptcha_secret_key'] ) ) ) : '';

		$settings['tracking_params'] = self::normalize_csv(
			isset( $raw['tracking_params'] ) ? (string) wp_unslash( $raw['tracking_params'] ) : $defaults['tracking_params'],
			false
		);
		if ( '' === $settings['tracking_params'] ) {
			$settings['tracking_params'] = $defaults['tracking_params'];
		}

		$settings['allowed_public_rest_namespaces'] = self::normalize_csv(
			isset( $raw['allowed_public_rest_namespaces'] ) ? (string) wp_unslash( $raw['allowed_public_rest_namespaces'] ) : $defaults['allowed_public_rest_namespaces'],
			true
		);
		if ( '' === $settings['allowed_public_rest_namespaces'] ) {
			$settings['allowed_public_rest_namespaces'] = $defaults['allowed_public_rest_namespaces'];
		}

		$rate_limit = isset( $raw['rate_limit_requests_per_minute'] ) ? absint( $raw['rate_limit_requests_per_minute'] ) : (int) $defaults['rate_limit_requests_per_minute'];
		$settings['rate_limit_requests_per_minute'] = max( 1, $rate_limit );

		return $settings;
	}

	private static function normalize_csv( $value, $allow_slashes ) {
		$items = array_filter(
			array_map( 'trim', explode( ',', (string) $value ) ),
			static function ( $item ) {
				return '' !== $item;
			}
		);

		$normalized = array();

		foreach ( $items as $item ) {
			$item = strtolower( $item );
			$item = $allow_slashes
				? preg_replace( '/[^a-z0-9._\\/-]/', '', $item )
				: preg_replace( '/[^a-z0-9_-]/', '', $item );

			if ( '' !== $item ) {
				$normalized[] = $item;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );

		return implode( ',', $normalized );
	}

	private static function compile_regex( $pattern ) {
		$pattern = trim( (string) $pattern );

		if ( '' === $pattern ) {
			$pattern = self::defaults()['spam_search_regex'];
		}

		$is_delimited = strlen( $pattern ) > 2 && preg_match( '/^(.).+\\1[imsxuADSUXJu]*$/', $pattern );
		$regex        = $is_delimited ? $pattern : '/' . str_replace( '/', '\\/', $pattern ) . '/i';

		set_error_handler( '__return_false' );
		$is_valid = false !== preg_match( $regex, '' );
		restore_error_handler();

		if ( $is_valid ) {
			return $regex;
		}

		return '/' . self::defaults()['spam_search_regex'] . '/i';
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'init', array( $this, 'maybe_rate_limit_requests' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_block_spam_search_queries' ), 1 );
		add_action( 'template_redirect', array( $this, 'maybe_validate_search_recaptcha' ), 2 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_search_queries' ), 3 );
		add_action( 'template_redirect', array( $this, 'maybe_canonicalize_urls' ), 15 );
		add_action( 'wp_head', array( $this, 'maybe_output_noindex_meta' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		add_filter( 'get_search_form', array( $this, 'filter_search_form' ) );
		add_filter( 'xmlrpc_enabled', array( $this, 'filter_xmlrpc_enabled' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'maybe_restrict_anonymous_rest' ), 10, 3 );
	}

	public function register_settings_page() {
		add_submenu_page(
			function_exists( 'savedpixel_admin_parent_slug' ) ? savedpixel_admin_parent_slug() : 'options-general.php',
			'SavedPixel SEO Shield',
			'SEO Shield',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_admin_assets() {
		if ( self::MENU_SLUG !== self::current_admin_page() ) {
			return;
		}

		savedpixel_admin_enqueue_preview_style( self::MENU_SLUG );
	}

	public function handle_admin_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) || self::MENU_SLUG !== self::current_admin_page() ) {
			return;
		}

		if ( isset( $_POST['spss_save_settings'] ) ) {
			check_admin_referer( 'spss_save_settings', 'spss_nonce' );

			$settings = self::sanitize_settings( $_POST, true );
			update_option( self::OPTION, $settings );
			$this->redirect_with_notice( 'saved' );
		}

		if ( isset( $_POST['spss_clear_log'] ) ) {
			check_admin_referer( 'spss_clear_log', 'spss_clear_log_nonce' );
			if ( false === get_option( self::LOG_OPTION, false ) ) {
				add_option( self::LOG_OPTION, array(), '', 'no' );
			} else {
				update_option( self::LOG_OPTION, array() );
			}
			$this->redirect_with_notice( 'log-cleared' );
		}
	}

	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'spss_notice' => sanitize_key( (string) $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_admin_page() {
		$settings    = self::settings();
		$log_entries = array_reverse( self::log_entries() );
		$log_count   = count( $log_entries );
		$notice      = isset( $_GET['spss_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['spss_notice'] ) ) : '';
		?>
		<?php savedpixel_admin_page_start( 'spss-page' ); ?>
			<header id="spss-header" class="sp-page-header">
				<div id="spss-header-main">
					<h1 id="spss-header-title" class="sp-page-title">SavedPixel SEO Shield</h1>
					<p id="spss-header-desc" class="sp-page-desc">Block junk search traffic, tighten crawl-facing endpoints, and manage SEO hardening rules from one SavedPixel admin page.</p>
				</div>
				<div id="spss-header-actions" class="sp-header-actions">
					<a id="spss-back-link" class="button" href="<?php echo esc_url( savedpixel_admin_page_url( savedpixel_admin_parent_slug() ) ); ?>">Back to Overview</a>
				</div>
			</header>

			<div id="spss-intro-note" class="sp-note">
				<p>Some protections on this page can block public search requests, crawl endpoints, or anonymous API traffic. Start with the defaults and enable stricter rules only when you control the site’s public access patterns.</p>
			</div>

			<?php if ( 'saved' === $notice ) : ?>
				<div id="spss-notice-saved" class="notice notice-success is-dismissible">
					<p>Settings saved.</p>
				</div>
			<?php elseif ( 'log-cleared' === $notice ) : ?>
				<div id="spss-notice-log-cleared" class="notice notice-success is-dismissible">
					<p>Shield log cleared.</p>
				</div>
			<?php endif; ?>

			<form id="spss-form" class="sp-stack" method="post">
				<?php wp_nonce_field( 'spss_save_settings', 'spss_nonce' ); ?>

				<section id="spss-search-shield-section">
					<div id="spss-search-shield-card" class="sp-card">
						<div class="sp-card__body">
							<h2 id="spss-search-shield-title">Search Shield</h2>
							<table id="spss-search-shield-table" class="form-table sp-form-table">
								<tr id="spss-row-block-spam-search-queries">
									<th><label for="spss-block-spam-search-queries">Spam search blocking</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-block-spam-search-queries" name="block_spam_search_queries" value="1" <?php checked( $settings['block_spam_search_queries'], 1 ); ?>>
											Return `410 Gone` for junk search patterns before redirect rules run.
										</label>
										<p class="description">Use the regex below to define which search values should be treated as spam.</p>
									</td>
								</tr>
								<tr id="spss-row-spam-search-regex">
									<th><label for="spss-spam-search-regex">Junk-search regex</label></th>
									<td>
										<input type="text" id="spss-spam-search-regex" name="spam_search_regex" class="regular-text code" value="<?php echo esc_attr( $settings['spam_search_regex'] ); ?>">
										<p class="description">SavedPixel accepts either a bare pattern such as <code>\.(jp|cn|ru)$</code> or a fully delimited PHP regex.</p>
									</td>
								</tr>
								<tr id="spss-row-redirect-search-queries">
									<th><label for="spss-redirect-search-queries">Redirect `?s=` requests</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-redirect-search-queries" name="redirect_search_queries" value="1" <?php checked( $settings['redirect_search_queries'], 1 ); ?>>
											Redirect non-junk search requests to the homepage with a `301`.
										</label>
									</td>
								</tr>
								<tr id="spss-row-noindex-search-pages">
									<th><label for="spss-noindex-search-pages">Noindex search pages</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-noindex-search-pages" name="noindex_search_pages" value="1" <?php checked( $settings['noindex_search_pages'], 1 ); ?>>
											Output <code>noindex, follow</code> on rendered search-result pages.
										</label>
										<p class="description">This only applies when search requests are allowed to render instead of redirecting.</p>
									</td>
								</tr>
								<tr id="spss-row-enable-search-recaptcha">
									<th><label for="spss-enable-search-recaptcha">Search reCAPTCHA</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-enable-search-recaptcha" name="enable_search_recaptcha" value="1" <?php checked( $settings['enable_search_recaptcha'], 1 ); ?>>
											Require Google reCAPTCHA validation before protected search requests are processed.
										</label>
										<p class="description">SavedPixel only validates reCAPTCHA when this is enabled and both keys below are present.</p>
									</td>
								</tr>
								<tr id="spss-row-recaptcha-site-key">
									<th><label for="spss-recaptcha-site-key">reCAPTCHA site key</label></th>
									<td>
										<input type="text" id="spss-recaptcha-site-key" name="recaptcha_site_key" class="regular-text" value="<?php echo esc_attr( $settings['recaptcha_site_key'] ); ?>">
									</td>
								</tr>
								<tr id="spss-row-recaptcha-secret-key">
									<th><label for="spss-recaptcha-secret-key">reCAPTCHA secret key</label></th>
									<td>
										<input type="text" id="spss-recaptcha-secret-key" name="recaptcha_secret_key" class="regular-text" value="<?php echo esc_attr( $settings['recaptcha_secret_key'] ); ?>">
									</td>
								</tr>
							</table>
						</div>
					</div>
				</section>

				<section id="spss-url-canonicalization-section">
					<div id="spss-url-canonicalization-card" class="sp-card">
						<div class="sp-card__body">
							<h2 id="spss-url-canonicalization-title">URL Canonicalization</h2>
							<table id="spss-url-canonicalization-table" class="form-table sp-form-table">
								<tr id="spss-row-strip-tracking-params">
									<th><label for="spss-strip-tracking-params">Strip tracking params</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-strip-tracking-params" name="strip_tracking_params" value="1" <?php checked( $settings['strip_tracking_params'], 1 ); ?>>
											Remove configured tracking parameters from public URLs and redirect to the cleaned URL.
										</label>
									</td>
								</tr>
								<tr id="spss-row-tracking-params">
									<th><label for="spss-tracking-params">Tracking param list</label></th>
									<td>
										<input type="text" id="spss-tracking-params" name="tracking_params" class="regular-text" value="<?php echo esc_attr( $settings['tracking_params'] ); ?>">
										<p class="description">Comma-separated query parameter names, for example <code>utm_source,utm_medium,ref</code>.</p>
									</td>
								</tr>
								<tr id="spss-row-force-https-redirect">
									<th><label for="spss-force-https-redirect">Force HTTPS</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-force-https-redirect" name="force_https_redirect" value="1" <?php checked( $settings['force_https_redirect'], 1 ); ?>>
											Redirect public frontend requests from HTTP to HTTPS.
										</label>
										<p class="description">Disabled by default so local or mixed-protocol environments are not forced into HTTPS unexpectedly.</p>
									</td>
								</tr>
							</table>
						</div>
					</div>
				</section>

				<section id="spss-bot-protection-section">
					<div id="spss-bot-protection-card" class="sp-card">
						<div class="sp-card__body">
							<h2 id="spss-bot-protection-title">Bot Protection</h2>
							<table id="spss-bot-protection-table" class="form-table sp-form-table">
								<tr id="spss-row-enable-rate-limit">
									<th><label for="spss-enable-rate-limit">Rate limit anonymous traffic</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-enable-rate-limit" name="enable_rate_limit" value="1" <?php checked( $settings['enable_rate_limit'], 1 ); ?>>
											Throttle anonymous public frontend requests by IP.
										</label>
										<p class="description">Logged-in users, admin, AJAX, cron, CLI, login, XML-RPC, and REST requests are excluded from this limit.</p>
									</td>
								</tr>
								<tr id="spss-row-rate-limit-requests-per-minute">
									<th><label for="spss-rate-limit-requests-per-minute">Requests per minute</label></th>
									<td>
										<input type="number" min="1" id="spss-rate-limit-requests-per-minute" name="rate_limit_requests_per_minute" class="small-text" value="<?php echo esc_attr( (string) $settings['rate_limit_requests_per_minute'] ); ?>">
									</td>
								</tr>
							</table>
						</div>
					</div>
				</section>

				<section id="spss-endpoint-hardening-section">
					<div id="spss-endpoint-hardening-card" class="sp-card">
						<div class="sp-card__body">
							<h2 id="spss-endpoint-hardening-title">Endpoint Hardening</h2>
							<table id="spss-endpoint-hardening-table" class="form-table sp-form-table">
								<tr id="spss-row-disable-xmlrpc">
									<th><label for="spss-disable-xmlrpc">Disable XML-RPC</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-disable-xmlrpc" name="disable_xmlrpc" value="1" <?php checked( $settings['disable_xmlrpc'], 1 ); ?>>
											Disable XML-RPC access.
										</label>
									</td>
								</tr>
								<tr id="spss-row-restrict-anonymous-rest">
									<th><label for="spss-restrict-anonymous-rest">Restrict anonymous REST</label></th>
									<td>
										<label>
											<input type="checkbox" id="spss-restrict-anonymous-rest" name="restrict_anonymous_rest" value="1" <?php checked( $settings['restrict_anonymous_rest'], 1 ); ?>>
											Allow anonymous REST access only for the namespaces listed below.
										</label>
									</td>
								</tr>
								<tr id="spss-row-allowed-public-rest-namespaces">
									<th><label for="spss-allowed-public-rest-namespaces">Public REST namespaces</label></th>
									<td>
										<input type="text" id="spss-allowed-public-rest-namespaces" name="allowed_public_rest_namespaces" class="regular-text" value="<?php echo esc_attr( $settings['allowed_public_rest_namespaces'] ); ?>">
										<p class="description">Comma-separated namespace prefixes, for example <code>wp/v2,oembed/1.0,wc/store</code>.</p>
									</td>
								</tr>
							</table>
						</div>
					</div>
				</section>

				<p id="spss-form-actions">
					<button id="spss-save-settings" type="submit" name="spss_save_settings" value="1" class="button button-primary">Save Settings</button>
				</p>
			</form>

			<section id="spss-log-section">
				<div id="spss-log-header" class="sp-card__header">
					<h2 id="spss-log-title" class="sp-card__title">Shield Log</h2>
					<div class="sp-header-actions">
						<span id="spss-log-count" class="sp-badge sp-badge--neutral"><?php echo esc_html( $log_count . ' items' ); ?></span>
						<?php if ( $log_count > 0 ) : ?>
							<form method="post">
								<?php wp_nonce_field( 'spss_clear_log', 'spss_clear_log_nonce' ); ?>
								<button id="spss-clear-log" type="submit" name="spss_clear_log" value="1" class="button">Clear Log</button>
							</form>
						<?php endif; ?>
					</div>
				</div>
				<div id="spss-log-card" class="sp-card">
					<div class="sp-card__body sp-card__body--flush">
						<?php if ( 0 === $log_count ) : ?>
							<p id="spss-log-empty" class="sp-empty">No shield events have been recorded yet.</p>
						<?php else : ?>
							<div id="spss-log-wrap" class="sp-table-wrap">
								<table id="spss-log-table" class="sp-table">
									<thead>
										<tr>
											<th>Time</th>
											<th>Rule</th>
											<th>Request</th>
											<th>IP</th>
											<th>Details</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $log_entries as $index => $entry ) : ?>
											<tr id="spss-log-row-<?php echo (int) $index; ?>">
												<td><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
												<td><?php echo esc_html( self::rule_label( (string) ( $entry['rule'] ?? '' ) ) ); ?></td>
												<td><?php echo esc_html( $entry['request'] ?? '' ); ?></td>
												<td><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
												<td><?php echo esc_html( $entry['details'] ?? '' ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</section>
		<?php savedpixel_admin_page_end(); ?>
		<?php
	}

	public function enqueue_frontend_assets() {
		$settings = self::settings();

		if ( ! $settings['enable_search_recaptcha'] || ! $this->recaptcha_configured( $settings ) || ! self::is_public_frontend_request() ) {
			return;
		}

		wp_enqueue_script(
			'spss-google-recaptcha',
			'https://www.google.com/recaptcha/api.js',
			array(),
			null,
			true
		);
	}

	public function filter_search_form( $form ) {
		$settings = self::settings();

		if ( ! $settings['enable_search_recaptcha'] || ! $this->recaptcha_configured( $settings ) || is_admin() ) {
			return $form;
		}

		if ( false !== strpos( $form, 'g-recaptcha' ) ) {
			return $form;
		}

		$markup = '<div class="spss-search-recaptcha"><div class="g-recaptcha" data-sitekey="' . esc_attr( $settings['recaptcha_site_key'] ) . '"></div></div>';

		if ( false !== stripos( $form, '</form>' ) ) {
			return preg_replace( '/<\/form>/i', $markup . '</form>', $form, 1 );
		}

		return $form . $markup;
	}

	public function maybe_block_spam_search_queries() {
		$settings = self::settings();

		if ( ! $settings['block_spam_search_queries'] || ! self::is_public_frontend_request() || ! self::has_search_query() ) {
			return;
		}

		$query = self::search_query();
		if ( '' === $query ) {
			return;
		}

		if ( preg_match( self::compile_regex( $settings['spam_search_regex'] ), $query ) ) {
			$this->log_event( 'spam_search_blocked', 'Blocked spam search query: ' . $query );
			wp_die(
				esc_html__( 'This search request has been blocked.', 'savedpixel-seo-shield' ),
				esc_html__( 'Request Blocked', 'savedpixel-seo-shield' ),
				array( 'response' => 410 )
			);
		}
	}

	public function maybe_validate_search_recaptcha() {
		$settings = self::settings();

		if ( ! $settings['enable_search_recaptcha'] || ! $this->recaptcha_configured( $settings ) || ! self::is_public_frontend_request() || ! self::has_search_query() ) {
			return;
		}

		$token = isset( $_REQUEST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g-recaptcha-response'] ) ) : '';
		if ( '' === $token ) {
			$this->log_event( 'search_recaptcha_failed', 'Search reCAPTCHA token missing.' );
			wp_die(
				esc_html__( 'reCAPTCHA verification failed.', 'savedpixel-seo-shield' ),
				esc_html__( 'Access Denied', 'savedpixel-seo-shield' ),
				array( 'response' => 403 )
			);
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $settings['recaptcha_secret_key'],
					'response' => $token,
					'remoteip' => self::client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_event( 'search_recaptcha_failed', 'reCAPTCHA API request failed: ' . $response->get_error_message() );
			wp_die(
				esc_html__( 'reCAPTCHA verification failed.', 'savedpixel-seo-shield' ),
				esc_html__( 'Access Denied', 'savedpixel-seo-shield' ),
				array( 'response' => 403 )
			);
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $result['success'] ) ) {
			$this->log_event( 'search_recaptcha_failed', 'Search reCAPTCHA rejected the request.' );
			wp_die(
				esc_html__( 'reCAPTCHA verification failed.', 'savedpixel-seo-shield' ),
				esc_html__( 'Access Denied', 'savedpixel-seo-shield' ),
				array( 'response' => 403 )
			);
		}
	}

	public function maybe_redirect_search_queries() {
		$settings = self::settings();

		if ( ! $settings['redirect_search_queries'] || ! self::is_public_frontend_request() || ! self::has_search_query() ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	public function maybe_output_noindex_meta() {
		$settings = self::settings();

		if ( is_admin() || ! $settings['noindex_search_pages'] || ! is_search() ) {
			return;
		}

		echo "<meta name=\"robots\" content=\"noindex, follow\">\n";
	}

	public function maybe_canonicalize_urls() {
		$settings = self::settings();

		if ( ! self::is_public_frontend_request() ) {
			return;
		}

		$query_args      = self::request_query_args();
		$should_redirect = false;

		if ( $settings['strip_tracking_params'] ) {
			foreach ( self::tracking_params( $settings ) as $tracking_param ) {
				foreach ( array_keys( $query_args ) as $query_key ) {
					if ( 0 !== strcasecmp( (string) $query_key, (string) $tracking_param ) ) {
						continue;
					}

					unset( $query_args[ $query_key ] );
					$should_redirect = true;
				}
			}
		}

		$target_scheme = is_ssl() ? 'https' : 'http';
		if ( $settings['force_https_redirect'] ) {
			if ( ! is_ssl() ) {
				$should_redirect = true;
			}

			$target_scheme = 'https';
		}

		if ( ! $should_redirect ) {
			return;
		}

		$target_url = home_url( self::relative_request_path(), $target_scheme );

		if ( ! empty( $query_args ) ) {
			$target_url = add_query_arg( $query_args, $target_url );
		}

		wp_safe_redirect( $target_url, 301 );
		exit;
	}

	public function maybe_rate_limit_requests() {
		$settings = self::settings();

		if ( ! $settings['enable_rate_limit'] || ! self::is_public_frontend_request() || is_user_logged_in() ) {
			return;
		}

		$ip = self::client_ip();
		if ( '' === $ip ) {
			return;
		}

		$key      = 'spss_rate_' . md5( $ip );
		$requests = (int) get_transient( $key );

		if ( $requests >= $settings['rate_limit_requests_per_minute'] ) {
			$this->log_event(
				'rate_limit_exceeded',
				sprintf( 'IP exceeded %d requests per minute.', (int) $settings['rate_limit_requests_per_minute'] )
			);
			wp_die(
				esc_html__( 'Too many requests.', 'savedpixel-seo-shield' ),
				esc_html__( 'Too Many Requests', 'savedpixel-seo-shield' ),
				array( 'response' => 429 )
			);
		}

		set_transient( $key, $requests + 1, MINUTE_IN_SECONDS );
	}

	public function filter_xmlrpc_enabled( $enabled ) {
		$settings = self::settings();

		return $settings['disable_xmlrpc'] ? false : $enabled;
	}

	public function maybe_restrict_anonymous_rest( $result, $server, $request ) {
		$settings = self::settings();

		if ( ! $settings['restrict_anonymous_rest'] || is_user_logged_in() || ! ( $request instanceof WP_REST_Request ) || null !== $result ) {
			return $result;
		}

		$route = ltrim( $request->get_route(), '/' );
		foreach ( self::allowed_rest_namespace_prefixes( $settings ) as $prefix ) {
			if ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) {
				return $result;
			}
		}

		$this->log_event( 'rest_request_blocked', 'Blocked anonymous REST route: /' . $route );

		return new WP_Error(
			'spss_rest_restricted',
			__( 'Anonymous REST access is restricted.', 'savedpixel-seo-shield' ),
			array( 'status' => 403 )
		);
	}

	private function recaptcha_configured( $settings ) {
		return '' !== $settings['recaptcha_site_key'] && '' !== $settings['recaptcha_secret_key'];
	}

	private static function log_entries() {
		$entries = get_option( self::LOG_OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	private function log_event( $rule, $details ) {
		$entries   = self::log_entries();
		$entries[] = array(
			'time'    => current_time( 'mysql' ),
			'rule'    => sanitize_key( (string) $rule ),
			'request' => self::request_summary(),
			'ip'      => self::client_ip(),
			'details' => sanitize_text_field( (string) $details ),
		);

		if ( count( $entries ) > self::LOG_MAX ) {
			$entries = array_slice( $entries, -self::LOG_MAX );
		}

		if ( false === get_option( self::LOG_OPTION, false ) ) {
			add_option( self::LOG_OPTION, $entries, '', 'no' );
		} else {
			update_option( self::LOG_OPTION, $entries );
		}
	}

	private static function rule_label( $rule ) {
		$labels = array(
			'spam_search_blocked'    => 'Spam Search',
			'search_recaptcha_failed' => 'Search reCAPTCHA',
			'rate_limit_exceeded'    => 'Rate Limit',
			'rest_request_blocked'   => 'REST Restriction',
		);

		return $labels[ $rule ] ?? $rule;
	}

	private static function tracking_params( $settings ) {
		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) $settings['tracking_params'] ) ),
				static function ( $item ) {
					return '' !== $item;
				}
			)
		);
	}

	private static function allowed_rest_namespace_prefixes( $settings ) {
		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) $settings['allowed_public_rest_namespaces'] ) ),
				static function ( $item ) {
					return '' !== $item;
				}
			)
		);
	}

	private static function current_admin_page() {
		if ( function_exists( 'savedpixel_current_admin_page' ) ) {
			return savedpixel_current_admin_page();
		}

		return isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
	}

	private static function has_search_query() {
		return isset( $_GET[ self::SEARCH_KEY ] );
	}

	private static function search_query() {
		return isset( $_GET[ self::SEARCH_KEY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SEARCH_KEY ] ) ) : '';
	}

	private static function request_path() {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH );

		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	private static function relative_request_path() {
		$request_path = self::request_path();
		$home_path    = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		$home_path = is_string( $home_path ) ? untrailingslashit( $home_path ) : '';

		if ( '' !== $home_path ) {
			if ( $request_path === $home_path ) {
				return '/';
			}

			if ( 0 === strpos( $request_path, $home_path . '/' ) ) {
				$request_path = substr( $request_path, strlen( $home_path ) );
			}
		}

		return '/' . ltrim( (string) $request_path, '/' );
	}

	private static function request_query_args() {
		$args = array();

		foreach ( wp_unslash( $_GET ) as $key => $value ) {
			$args[ (string) $key ] = is_array( $value )
				? map_deep( $value, 'sanitize_text_field' )
				: sanitize_text_field( (string) $value );
		}

		return $args;
	}

	private static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function request_summary() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return trim( $method . ' ' . $uri );
	}

	private static function is_public_frontend_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		if ( self::is_login_request() || self::is_xmlrpc_request() || self::is_rest_request() ) {
			return false;
		}

		return true;
	}

	private static function is_login_request() {
		$path = self::request_path();

		return false !== strpos( $path, 'wp-login.php' );
	}

	private static function is_xmlrpc_request() {
		$path = self::request_path();

		return false !== strpos( $path, 'xmlrpc.php' );
	}

	private static function is_rest_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		$path = self::request_path();
		if ( 0 === strpos( $path, '/wp-json/' ) || '/wp-json' === $path ) {
			return true;
		}

		return isset( $_GET['rest_route'] );
	}
}
