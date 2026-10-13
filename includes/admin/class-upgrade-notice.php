<?php
/**
 * Global dismissible "Upgrade to Pro" admin notice.
 *
 * Renders on every WP admin screen (except the EMCP Tools own screens to
 * avoid redundancy with the header CTA there). Dismissed-per-user via
 * user_meta — once dismissed, stays dismissed indefinitely.
 *
 * @package EMCP_Tools
 * @since   1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMCP_Tools_Upgrade_Notice {

	/**
	 * User-meta key that records when this user dismissed the notice.
	 * Value: Unix timestamp, or empty/false if never dismissed.
	 */
	const META_KEY = 'emcp_tools_upgrade_notice_dismissed';

	/**
	 * AJAX nonce action name.
	 */
	const NONCE_ACTION = 'emcp_tools_dismiss_upgrade_notice';

	public function init(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_emcp_tools_dismiss_upgrade_notice', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Decide whether the notice should render on the current request.
	 */
	private function should_show(): bool {
		// Admins only — non-managers can't act on the CTA anyway.
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Hide for sites with an active Pro license.
		if ( function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code() ) {
			return false;
		}

		// Hide if the current user has dismissed it.
		if ( get_user_meta( get_current_user_id(), self::META_KEY, true ) ) {
			return false;
		}

		// Hide on the EMCP Tools own admin screens — the header already shows
		// an Upgrade button there, and we don't want two CTAs stacked.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' !== $page && 0 === strpos( $page, 'emcp-tools' ) ) {
			return false;
		}

		return true;
	}

	/** Loads the banner's style and dismiss script only where it shows. */
	public function enqueue(): void {
		if ( ! $this->should_show() ) {
			return;
		}
		wp_enqueue_style( 'emcp-tools-upgrade-notice', EMCP_TOOLS_URL . 'assets/css/upgrade-notice.css', array(), EMCP_TOOLS_VERSION );
		wp_enqueue_script( 'emcp-tools-upgrade-notice', EMCP_TOOLS_URL . 'assets/js/upgrade-notice.js', array(), EMCP_TOOLS_VERSION, true );
	}

	public function maybe_render(): void {
		if ( ! $this->should_show() ) {
			return;
		}

		$upgrade_url = function_exists( 'emcp_tools_upgrade_url' )
			? emcp_tools_upgrade_url()
			: 'https://emcptools.com/pricing';
		$docs_url    = 'https://emcptools.com/pricing';
		$nonce       = wp_create_nonce( self::NONCE_ACTION );
		?>

		<div class="emcp-upgrade-banner" data-emcp-nonce="<?php echo esc_attr( $nonce ); ?>">

			<svg class="emcp-upgrade-banner__shape emcp-upgrade-banner__shape--a" viewBox="0 0 200 200" aria-hidden="true">
				<circle cx="100" cy="100" r="90" fill="rgba(255,255,255,0.08)" />
				<circle cx="100" cy="100" r="55" fill="rgba(255,255,255,0.06)" />
			</svg>
			<svg class="emcp-upgrade-banner__shape emcp-upgrade-banner__shape--b" viewBox="0 0 100 100" aria-hidden="true">
				<polygon points="50,5 95,80 5,80" fill="rgba(255,255,255,0.07)" />
			</svg>
			<svg class="emcp-upgrade-banner__shape emcp-upgrade-banner__shape--c" viewBox="0 0 100 100" aria-hidden="true">
				<rect x="10" y="10" width="80" height="80" rx="14" fill="rgba(255,255,255,0.05)" transform="rotate(18 50 50)" />
			</svg>

			<button type="button" class="emcp-upgrade-banner__dismiss" aria-label="<?php esc_attr_e( 'Dismiss this notice', 'emcp-tools' ); ?>">
				<svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path d="M5.3 4.3a1 1 0 011.4 0L10 7.6l3.3-3.3a1 1 0 111.4 1.4L11.4 9l3.3 3.3a1 1 0 11-1.4 1.4L10 10.4l-3.3 3.3a1 1 0 11-1.4-1.4L8.6 9 5.3 5.7a1 1 0 010-1.4z" fill="currentColor"/></svg>
			</button>

			<div class="emcp-upgrade-banner__content">
				<div class="emcp-upgrade-banner__lede">
					<span class="emcp-upgrade-banner__eyebrow"><?php esc_html_e( 'EMCP Tools Pro', 'emcp-tools' ); ?></span>
					<h2 class="emcp-upgrade-banner__title">
						<?php esc_html_e( 'Give your AI a copilot for all of WordPress, not just Elementor.', 'emcp-tools' ); ?>
					</h2>
					<div class="emcp-upgrade-banner__actions">
					<a class="emcp-upgrade-banner__btn emcp-upgrade-banner__btn--primary" href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Upgrade to Pro', 'emcp-tools' ); ?>
						<svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path d="M11 3a1 1 0 100 2h2.6L7.3 11.3a1 1 0 101.4 1.4L15 6.4V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z" fill="currentColor"/><path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 100-2H5z" fill="currentColor"/></svg>
					</a>
					<a class="emcp-upgrade-banner__btn emcp-upgrade-banner__btn--ghost" href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'What\'s included', 'emcp-tools' ); ?>
					</a>
				</div>
				</div>

				<ul class="emcp-upgrade-banner__features">
					<li>
						<span class="emcp-upgrade-banner__check" aria-hidden="true">&#10003;</span>
						<div class="emcp-upgrade-banner__feature-text">
							<strong><?php esc_html_e( 'AI Chat in your editor', 'emcp-tools' ); ?></strong>
							<span><?php esc_html_e( 'build and edit by chatting, local or cloud models', 'emcp-tools' ); ?></span>
						</div>
					</li>
					<li>
						<span class="emcp-upgrade-banner__check" aria-hidden="true">&#10003;</span>
						<div class="emcp-upgrade-banner__feature-text">
							<strong><?php esc_html_e( 'Speaks your whole stack', 'emcp-tools' ); ?></strong>
							<span><?php esc_html_e( 'ACF, WooCommerce, SEO and form plugins', 'emcp-tools' ); ?></span>
						</div>
					</li>
					<li>
						<span class="emcp-upgrade-banner__check" aria-hidden="true">&#10003;</span>
						<div class="emcp-upgrade-banner__feature-text">
							<strong><?php esc_html_e( 'SEO and Accessibility toolkit', 'emcp-tools' ); ?></strong>
							<span><?php esc_html_e( 'audit and auto-fix any page', 'emcp-tools' ); ?></span>
						</div>
					</li>
					<li>
						<span class="emcp-upgrade-banner__check" aria-hidden="true">&#10003;</span>
						<div class="emcp-upgrade-banner__feature-text">
							<strong><?php esc_html_e( 'Widget Builder', 'emcp-tools' ); ?></strong>
							<span><?php esc_html_e( 'custom Elementor widgets from a prompt', 'emcp-tools' ); ?></span>
						</div>
					</li>
					<li>
						<span class="emcp-upgrade-banner__check" aria-hidden="true">&#10003;</span>
						<div class="emcp-upgrade-banner__feature-text">
							<strong><?php esc_html_e( '100 Premium Prompts', 'emcp-tools' ); ?></strong>
							<span><?php esc_html_e( 'landing pages and complete websites across 20 categories', 'emcp-tools' ); ?></span>
						</div>
					</li>
					<li>
						<span class="emcp-upgrade-banner__check" aria-hidden="true">&#10003;</span>
						<div class="emcp-upgrade-banner__feature-text">
							<strong><?php esc_html_e( 'Templates Library', 'emcp-tools' ); ?></strong>
							<span><?php esc_html_e( 'one-click, apply to any page', 'emcp-tools' ); ?></span>
						</div>
					</li>
				</ul>

				
			</div>
		</div>

		<?php
	}

	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		update_user_meta( get_current_user_id(), self::META_KEY, time() );
		wp_send_json_success();
	}
}
