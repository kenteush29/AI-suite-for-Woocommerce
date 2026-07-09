<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted update checker: pulls updates for this plugin directly from the
 * GitHub Releases of the repository. Public repo — no token required.
 *
 * Each release must carry a ZIP asset whose name contains the plugin slug and
 * whose top-level folder is the plugin slug, so WordPress installs it to the
 * correct directory. Handled by .github/workflows/release-restock.yml.
 */
final class RSTK_Updater {

	private const OWNER     = 'kenteush29';
	private const REPO      = 'AI-suite-for-Woocommerce';
	private const CACHE_KEY = 'rstk_gh_latest_release';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	private string $basename; // restock-for-woocommerce/restock-for-woocommerce.php
	private string $slug;     // restock-for-woocommerce
	private string $version;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->basename = plugin_basename( RSTK_FILE );
		$this->slug     = dirname( $this->basename );
		$this->version  = RSTK_VERSION;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_details' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 0 );
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->version, '>' ) && $release['zip_url'] ) {
			$transient->response[ $this->basename ] = (object) [
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $release['version'],
				'url'         => $release['html_url'],
				'package'     => $release['zip_url'],
				'tested'      => get_bloginfo( 'version' ),
			];
		} else {
			$transient->no_update[ $this->basename ] = (object) [
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => $this->version,
				'url'         => $release['html_url'] ?? '',
				'package'     => '',
			];
		}

		return $transient;
	}

	public function plugin_details( $result, string $action, $args ) {
		if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Restock for WooCommerce',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . self::OWNER . '">' . esc_html( self::OWNER ) . '</a>',
			'homepage'      => $release['html_url'],
			'download_link' => $release['zip_url'],
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published_at'],
			'sections'      => [
				'description' => 'Restock backlog for WooCommerce: out-of-stock product-lines ranked by total sales.',
				'changelog'   => $release['body'] ? wpautop( wp_kses_post( $release['body'] ) ) : 'See GitHub releases.',
			],
		];
	}

	private function get_latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases', self::OWNER, self::REPO );
		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Restock-For-WooCommerce-Updater',
			],
		] );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_site_transient( self::CACHE_KEY, [ 'version' => $this->version, 'zip_url' => '', 'html_url' => '', 'published_at' => '', 'body' => '' ], 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			return null;
		}

		// Find the newest release that ships an asset for THIS plugin (asset name
		// contains the slug). This lets the repo host several plugins safely.
		foreach ( $releases as $rel ) {
			if ( ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) || empty( $rel['tag_name'] ) ) {
				continue;
			}
			$zip_url = '';
			foreach ( $rel['assets'] ?? [] as $asset ) {
				$name = $asset['name'] ?? '';
				if ( strpos( $name, $this->slug ) !== false && substr( $name, -4 ) === '.zip' ) {
					$zip_url = $asset['browser_download_url'] ?? '';
					break;
				}
			}
			if ( ! $zip_url ) {
				continue;
			}

			// Extract a semver from tags like "restock-v1.2.3" or "v1.2.3".
			if ( ! preg_match( '/(\d+\.\d+(?:\.\d+)?)/', (string) $rel['tag_name'], $m ) ) {
				continue;
			}

			$info = [
				'version'      => $m[1],
				'zip_url'      => $zip_url,
				'html_url'     => $rel['html_url'] ?? '',
				'published_at' => $rel['published_at'] ?? '',
				'body'         => $rel['body'] ?? '',
			];
			set_site_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
			return $info;
		}

		return null;
	}

	public function clear_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}
}
