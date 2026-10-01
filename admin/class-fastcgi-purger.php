<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://rtcamp.com/nginx-helper/
 * @since      2.0.0
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 */

/**
 * Description of FastCGI_Purger
 *
 * @package    nginx-helper
 * @subpackage nginx-helper/admin
 * @author     rtCamp
 */
class FastCGI_Purger extends Purger {

	/**
	 * Function to purge url.
	 *
	 * @param string $url URL.
	 * @param bool   $feed Weather it is feed or not.
	 */
	public function purge_url( $url, $feed = true ) {

		global $nginx_helper_admin;

		/**
		 * Filters the URL to be purged.
		 *
		 * @since 2.1.0
		 *
		 * @param string $url URL to be purged.
		 */
		$url = apply_filters( 'rt_nginx_helper_purge_url', $url );

		$this->log( '- Purging URL | ' . $url );

		$parse = wp_parse_url( $url );

		// Pas de composant path (ex. home_url() sans slash final, ou un permalink de
		// variation/produit qui se résout à scheme://host) → défaut '/', PAS ''.
		// Avec '', la branche get_request construit un `…/purge` NU (sans slash final)
		// qui ne matche AUCUNE location purge nginx → tombe dans @wordpress → un render
		// 404 WordPress complet (php-fpm) par purge. '/' donne `…/purge/` → vraie purge.
		if ( empty( $parse['path'] ) ) {
			$parse['path'] = '/';
		}

		switch ( $nginx_helper_admin->options['purge_method'] ) {

			case 'unlink_files':
				$_url_purge_base = "http://localhost/" . $parse['path'];
				$_url_purge      = $_url_purge_base;

				if ( ! empty( $parse['query'] ) ) {
					$_url_purge .= '?' . $parse['query'];
				}

				$this->delete_cache_file_for( $_url_purge );

				if ( $feed && NGINX_FEED_PURGE ) {

					$feed_url = rtrim( $_url_purge_base, '/' ) . '/feed/';
					$this->delete_cache_file_for( $feed_url );
					$this->delete_cache_file_for( $feed_url . 'atom/' );
					$this->delete_cache_file_for( $feed_url . 'rdf/' );

				}
				break;

			case 'get_request':
				// Go to default case.
			default:
				$_url_purge_base = $this->purge_base_url() . $parse['path'];
				$_url_purge      = $_url_purge_base;

				if ( isset( $parse['query'] ) && '' !== $parse['query'] ) {
					$_url_purge .= '?' . $parse['query'];
				}

				$args = array(
					'headers' => array(
						'Host' => $parse['host'],
						'X-Purge-Cache' => 'true'
					),
					'cookies' => array(
						'trial_bypass' => 'true'
					),
					'timeout' => 30
				);

				$this->do_remote_get( $_url_purge, $args);

				if ( $feed && NGINX_FEED_PURGE) {

					$feed_url = rtrim( $_url_purge_base, '/' ) . '/feed/';
					$this->do_remote_get( $feed_url, $args );
					$this->do_remote_get( $feed_url . 'atom/', $args );
					$this->do_remote_get( $feed_url . 'rdf/', $args );

				}
				break;

		}
		/**
		 * Fire an action after the FastCGI cache has been purged.
		 *
		 * @since 2.1.0
		 */
		do_action('rt_nginx_helper_fastcgi_purge_url', $url);

	}

	/**
	 * Function to custom purge urls.
	 */
	public function custom_purge_urls() {

		global $nginx_helper_admin;

		$parse = wp_parse_url( home_url() );

		$purge_urls = isset( $nginx_helper_admin->options['purge_url'] ) && ! empty( $nginx_helper_admin->options['purge_url'] ) ?
			explode( "\r\n", $nginx_helper_admin->options['purge_url'] ) : array();

		/**
		 * Allow plugins/themes to modify/extend urls.
		 *
		 * @param array $purge_urls URLs which needs to be purged.
		 * @param bool  $wildcard   If wildcard in url is allowed or not. default false.
		 */
		$purge_urls = apply_filters( 'rt_nginx_helper_purge_urls', $purge_urls, false );

		switch ( $nginx_helper_admin->options['purge_method'] ) {

			case 'unlink_files':
				$_url_purge_base = "http://localhost/";

				if ( is_array( $purge_urls ) && ! empty( $purge_urls ) ) {

					foreach ( $purge_urls as $purge_url ) {

						$purge_url = trim( $purge_url );

						if ( strpos( $purge_url, '*' ) === false ) {

							$purge_url = $_url_purge_base . $purge_url;
							$this->log( '- Purging URL | ' . $purge_url );
							$this->delete_cache_file_for( $purge_url );

						}
					}
				}
				break;

			case 'get_request':
				// Go to default case.
			default:
				$_url_purge_base = $this->purge_base_url();

				if ( is_array( $purge_urls ) && ! empty( $purge_urls ) ) {

					foreach ( $purge_urls as $purge_url ) {

						$purge_url = trim( $purge_url );

						if ( strpos( $purge_url, '*' ) === false ) {

							$purge_url = $_url_purge_base . $purge_url;
							$this->log( '- Purging URL | ' . $purge_url );
							$this->do_remote_get( $purge_url );

						}
					}
				}
				break;

		}

	}

	/**
	 * Purge le cache de PAGES (FastCGI) globalement.
	 *
	 * NE touche NI l'opcache (bytecode PHP) NI l'object cache (APCu/Redis) : un vidage de
	 * cache de PAGES n'a aucun rapport avec le CODE compilé. Resetter l'opcache ici
	 * recompilait 23k+ fichiers sous forte concurrence → fenêtres d'incohérence de classes
	 * → fatales opcache croisées entre plugins. Pour un « tout vider » délibéré (opcache +
	 * object cache), c'est le HARD FLUSH manuel (endpoint Faaaster / déploiement), pas ici.
	 *
	 * Hooks automatiques : purge REPORTÉE en fin de requête, une seule fois. Elle suit
	 * donc la DERNIÈRE modification de la requête (une mise à jour automatique enchaîne
	 * plusieurs vidages) et ne tombe pas pendant le mode maintenance d'une mise à jour, qui
	 * ferait servir la page 503 aux visiteurs à la place des pages en cache. Pas de
	 * regroupement entre requêtes : il jetait la purge qui suivait un second changement
	 * (pages périmées jusqu'à expiration) ; la tempête RUCSS est traitée par des purges
	 * scopées dans faaaster-wp-rocket.php. $force = true : action manuelle, immédiate.
	 * Les purges SCOPÉES (purge_url/purge_post) ne passent pas par ici → immédiates.
	 *
	 * @param bool $force Purge immédiate (action manuelle). Défaut false (hooks auto).
	 */
	public function purge_all( $force = false ) {
		// Déjà en shutdown : un rappel ajouté maintenant à priorité 0 ne serait plus exécuté.
		if ( $force || did_action( 'shutdown' ) ) {
			$this->purge_pages( (bool) $force );
			return;
		}
		if ( $this->purge_all_scheduled ) {
			return;
		}
		$this->purge_all_scheduled = true;
		$this->log( '- purge_all (pages) programmée en fin de requête' );
		add_action( 'shutdown', function () {
			$this->purge_pages( false );
		}, 0 );
	}

	/**
	 * Purge de fin de requête déjà programmée.
	 *
	 * @var bool
	 */
	private $purge_all_scheduled = false;

	/**
	 * Vide le cache de PAGES FastCGI (et Pagespeed) maintenant.
	 *
	 * @param bool $force Action manuelle (pour le log).
	 */
	private function purge_pages( $force ) {
		// FastCGI SEULEMENT (pages). PAS de opcache_reset(), PAS de wp_cache_flush() : ce
		// sont des caches de CODE, vidés uniquement par le hard flush manuel délibéré.
		$this->do_remote_get( "http://localhost/purge-all" );
		touch( '/tmp/pagespeed/cache.flush' );

		$this->log( '* Purged FastCGI page cache' . ( $force ? ' (forcé)' : '' ) );

		/**
		 * Fire an action after the FastCGI cache has been purged.
		 *
		 * @since 2.1.0
		 */
		do_action( 'rt_nginx_helper_after_fastcgi_purge_all' );
	}

	/**
	 * Constructs the base url to call when purging using the "get_request" method.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	private function purge_base_url() {

		$parse = wp_parse_url( home_url() );

		/**
		 * Filter to change purge suffix for FastCGI cache.
		 *
		 * @param string $suffix Purge suffix. Default is purge.
		 *
		 * @since 2.2.0
		 */
		$path = apply_filters( 'rt_nginx_helper_fastcgi_purge_suffix', 'purge' );

		// Prevent users from inserting a trailing '/' that could break the url purging.
		$path = trim( $path, '/' );

		$purge_url_base = "http://localhost/" . $path;

		/**
		 * Filter to change purge URL base for FastCGI cache.
		 *
		 * @param string $purge_url_base Purge URL base.
		 *
		 * @since 2.2.0
		 */
		$purge_url_base = apply_filters( 'rt_nginx_helper_fastcgi_purge_url_base', $purge_url_base );

		// Prevent users from inserting a trailing '/' that could break the url purging.
		return untrailingslashit( $purge_url_base );

	}

}
