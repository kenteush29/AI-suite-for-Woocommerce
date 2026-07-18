<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Settings" admin page: a single page with tabs that hosts the configuration
 * screens that are set up once and rarely touched again — Google Merchant
 * Center connection/accounts and the AI Marketing Assistant's API key and
 * country pools. Regular day-to-day work (promotions, the AI calendar) lives
 * on its own dedicated pages (Marketing Events, Discounts), not here.
 */
final class DZE_Settings {

	public const MENU_SLUG = 'dazont-ecom-settings';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			DZE_Restock::MENU_SLUG,
			__( 'Settings', 'dazont-ecom' ),
			__( 'Settings', 'dazont-ecom' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dazont-ecom' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'gmc';
		$tabs = [
			'gmc' => __( 'Google Merchant Center', 'dazont-ecom' ),
			'ai'  => __( 'AI Marketing Assistant', 'dazont-ecom' ),
		];
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'gmc';
		}
		?>
		<div class="wrap dze-wrap">
			<h1><?php esc_html_e( 'Dazont Ecom Settings', 'dazont-ecom' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) :
					$url = add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => $key ], admin_url( 'admin.php' ) );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<div class="dze-settings-tab" style="margin-top:18px;">
				<?php
				if ( 'ai' === $tab ) {
					if ( class_exists( 'DZE_Marketing_Ai' ) ) {
						DZE_Marketing_Ai::instance()->render_settings_section();
					}
				} elseif ( class_exists( 'DZE_Gmc' ) ) {
						DZE_Gmc::instance()->render_settings_page();
				}
				?>
			</div>
		</div>
		<?php
	}
}
