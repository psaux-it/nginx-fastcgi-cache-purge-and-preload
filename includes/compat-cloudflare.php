<?php
/**
 * Cloudflare APO integration — keep Cloudflare cache in sync when Nginx cache purges.
 * Description: Mirrors NPP purge actions to Cloudflare APO (full purge + strict URL purge list).
 * Version: 2.1.4
 * Author: Hasan CALISIR
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'nppp_cloudflare_apo_is_available' ) ) {
    function nppp_cloudflare_apo_is_available(): bool {
        return class_exists( '\Cloudflare\APO\WordPress\Hooks' ) && defined( 'CLOUDFLARE_PLUGIN_DIR' );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_get_hooks' ) ) {
    function nppp_cloudflare_apo_get_hooks() {
        static $hooks = null;

        if ( $hooks instanceof \Cloudflare\APO\WordPress\Hooks ) {
            return $hooks;
        }

        if ( ! nppp_cloudflare_apo_is_available() ) {
            return null;
        }

        $config_path = trailingslashit( CLOUDFLARE_PLUGIN_DIR ) . 'config.json';
        if ( ! is_readable( $config_path ) ) {
            return null;
        }

        try {
            $hooks = new \Cloudflare\APO\WordPress\Hooks();
        } catch ( \Throwable $e ) {
            return null;
        }

        return $hooks;
    }
}

/**
 * Normalize host for safe comparisons (lowercase + IDN to ASCII).
 */
if ( ! function_exists( 'nppp_cloudflare_apo_normalize_host' ) ) {
    function nppp_cloudflare_apo_normalize_host( string $host ): string {
        $host = strtolower( trim( $host ) );

        // Normalize IDN if Cloudflare plugin is present (polyfill-safe).
        if ( class_exists( '\Cloudflare\APO\IntlUtil' ) ) {
            $ascii = \Cloudflare\APO\IntlUtil::idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
            if ( is_string( $ascii ) && $ascii !== '' ) {
                $host = strtolower( $ascii );
            }
        }

        return $host;
    }
}

/**
 * True if host is exactly the zone base OR a real subdomain of it.
 */
if ( ! function_exists( 'nppp_cloudflare_apo_host_in_zone' ) ) {
    function nppp_cloudflare_apo_host_in_zone( string $host, string $zone_base ): bool {
        $host      = nppp_cloudflare_apo_normalize_host( $host );
        $zone_base = nppp_cloudflare_apo_normalize_host( $zone_base );

        if ( $host === '' || $zone_base === '' ) {
            return false;
        }

        if ( $host === $zone_base ) {
            return true;
        }

        // Uses Cloudflare plugin helper that enforces the '.' boundary.
        return class_exists( '\Cloudflare\APO\WordPress\Utils' )
            && \Cloudflare\APO\WordPress\Utils::isSubdomainOf( $host, $zone_base );
    }
}

/**
 * Build and cache a Cloudflare runtime bundle (client + datastore + zone tag).
 * Cached per-request.
 */
if ( ! function_exists( 'nppp_cloudflare_apo_get_runtime' ) ) {
    function nppp_cloudflare_apo_get_runtime(): ?array {
        static $runtime = null;

        if ( is_array( $runtime ) ) {
            return $runtime;
        }

        if ( ! nppp_cloudflare_apo_is_available() ) {
            return null;
        }

        $config_path = trailingslashit( CLOUDFLARE_PLUGIN_DIR ) . 'config.json';
        if ( ! is_readable( $config_path ) ) {
            return null;
        }

        $config_raw = file_get_contents( $config_path );
        if ( ! is_string( $config_raw ) || '' === $config_raw ) {
            return null;
        }

        try {
            $config      = new \Cloudflare\APO\Integration\DefaultConfig( $config_raw );
            $logger      = new \Cloudflare\APO\Integration\DefaultLogger( $config->getValue( 'debug' ) );
            $store       = new \Cloudflare\APO\WordPress\DataStore( $logger );

            // If CF plugin isn't authenticated/configured, don't try to purge.
            if ( method_exists( $store, 'getClientV4APIKey' ) ) {
                $key = $store->getClientV4APIKey();
                if ( empty( $key ) ) {
                    return null;
                }
            }

            $wp_api      = new \Cloudflare\APO\WordPress\WordPressAPI( $store );
            $integration = new \Cloudflare\APO\Integration\DefaultIntegration( $config, $wp_api, $store, $logger );
            $client      = new \Cloudflare\APO\WordPress\WordPressClientAPI( $integration );
        } catch ( \Throwable $e ) {
            return null;
        }

        // Domain candidates (helps when home_url is www but zone is apex).
        $candidates = array();

        $domains = $wp_api->getDomainList();
        if ( is_array( $domains ) && ! empty( $domains[0] ) && is_string( $domains[0] ) ) {
            $candidates[] = $domains[0];
        }

        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( is_string( $home_host ) && $home_host !== '' ) {
            $candidates[] = $home_host;

            if ( strpos( $home_host, 'www.' ) === 0 ) {
                $candidates[] = substr( $home_host, 4 );
            }
        }

        $candidates = array_values( array_unique( array_filter( $candidates, 'is_string' ) ) );
        if ( empty( $candidates ) ) {
            return null;
        }

        $domain   = '';
        $zone_tag = '';

        foreach ( $candidates as $candidate ) {
            $zt = $client->getZoneTag( $candidate );
            if ( is_string( $zt ) && $zt !== '' ) {
                $domain   = $candidate;
                $zone_tag = $zt;
                break;
            }
        }

        if ( $domain === '' || $zone_tag === '' ) {
            return null;
        }

        // Normalize to base domain for host matching (handles www vs apex).
        $domain_base = preg_replace( '#^www\.#i', '', $domain );
        if ( ! is_string( $domain_base ) || $domain_base === '' ) {
            $domain_base = $domain;
        }

        $runtime = array(
            'client'      => $client,
            'store'       => $store,
            'wp_api'      => $wp_api,
            'domain'      => $domain,
            'domain_base' => $domain_base,
            'zone_tag'    => $zone_tag,
        );

        return $runtime;
    }
}

/**
 * Match Cloudflare plugin behavior: only purge managed HTML cache when APO or Plugin-Specific Cache is enabled.
 */
if ( ! function_exists( 'nppp_cloudflare_apo_is_html_cache_enabled' ) ) {
    function nppp_cloudflare_apo_is_html_cache_enabled( \Cloudflare\APO\WordPress\DataStore $store ): bool {
        $apo = $store->getPluginSetting( \Cloudflare\APO\API\Plugin::SETTING_AUTOMATIC_PLATFORM_OPTIMIZATION );
        $psc = $store->getPluginSetting( \Cloudflare\APO\API\Plugin::SETTING_PLUGIN_SPECIFIC_CACHE );

        $apo_on = is_array( $apo )
            && isset( $apo[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] )
            && $apo[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] !== false
            && $apo[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] !== 'off';

        $psc_on = is_array( $psc )
            && isset( $psc[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] )
            && $psc[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] !== false
            && $psc[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] !== 'off';

        return $apo_on || $psc_on;
    }
}

/**
 * Queue URLs for purge and flush once at shutdown (reduces API calls).
 */
if ( ! function_exists( 'nppp_cloudflare_apo_queue_urls' ) ) {
    function nppp_cloudflare_apo_queue_urls( array $urls ): void {
        if ( empty( $urls ) ) {
            return;
        }

        if ( empty( $GLOBALS['NPPP_CF_APO_URL_QUEUE'] ) || ! is_array( $GLOBALS['NPPP_CF_APO_URL_QUEUE'] ) ) {
            $GLOBALS['NPPP_CF_APO_URL_QUEUE'] = array();
        }

        foreach ( $urls as $u ) {
            if ( is_string( $u ) && $u !== '' ) {
                $GLOBALS['NPPP_CF_APO_URL_QUEUE'][] = $u;
            }
        }

        if ( empty( $GLOBALS['NPPP_CF_APO_SHUTDOWN_HOOKED'] ) ) {
            $GLOBALS['NPPP_CF_APO_SHUTDOWN_HOOKED'] = true;
            add_action( 'shutdown', 'nppp_cloudflare_apo_flush_queue', 1 );
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_flush_queue' ) ) {
    function nppp_cloudflare_apo_flush_queue(): void {
        // If we did a purge-all, don't bother with file purges.
        if ( ! empty( $GLOBALS['NPPP_CF_APO_PURGE_ALL_RAN'] ) ) {
            $GLOBALS['NPPP_CF_APO_URL_QUEUE'] = array();
            return;
        }

        $urls = $GLOBALS['NPPP_CF_APO_URL_QUEUE'] ?? array();
        if ( ! is_array( $urls ) || empty( $urls ) ) {
            return;
        }

        // Clear queue early to avoid re-entrancy issues.
        $GLOBALS['NPPP_CF_APO_URL_QUEUE'] = array();

        nppp_cloudflare_apo_purge_exact_urls( $urls );
    }
}

/**
 * Purge ONLY the exact URLs NPP purged (strict sync).
 */
if ( ! function_exists( 'nppp_cloudflare_apo_purge_exact_urls' ) ) {
    function nppp_cloudflare_apo_purge_exact_urls( array $urls ): void {
        $runtime = nppp_cloudflare_apo_get_runtime();
        if ( ! $runtime ) {
            return;
        }

        if ( ! nppp_cloudflare_apo_is_html_cache_enabled( $runtime['store'] ) ) {
            return;
        }

        // Clean, validate, dedupe.
        $urls = array_values( array_unique( array_filter( $urls, 'is_string' ) ) );
        if ( empty( $urls ) ) {
            return;
        }

        // Keep only URLs in this zone (use base domain; safe subdomain matching).
        $domain_base = (string) $runtime['domain_base'];
        $filtered    = array();

        foreach ( $urls as $url ) {
            if ( false === wp_http_validate_url( $url ) ) {
                continue;
            }
            $host = wp_parse_url( $url, PHP_URL_HOST );
            if ( ! is_string( $host ) || $host === '' ) {
                continue;
            }
            if ( nppp_cloudflare_apo_host_in_zone( $host, $domain_base ) ) {
                $filtered[] = $url;
            }
        }

        $urls = array_values( array_unique( $filtered ) );
        if ( empty( $urls ) ) {
            return;
        }

        // Cloudflare purge limit: 30 files per request.
        foreach ( array_chunk( $urls, 30 ) as $chunk ) {
            $runtime['client']->zonePurgeFiles( $runtime['zone_tag'], $chunk );
        }

        // Mobile variant if APO Cache By Device Type is enabled.
        $device_setting = $runtime['store']->getPluginSetting(
            \Cloudflare\APO\API\Plugin::SETTING_AUTOMATIC_PLATFORM_OPTIMIZATION_CACHE_BY_DEVICE_TYPE
        );

        $device_on = is_array( $device_setting )
            && isset( $device_setting[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] )
            && $device_setting[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] !== false
            && $device_setting[ \Cloudflare\APO\API\Plugin::SETTING_VALUE_KEY ] !== 'off';

        if ( $device_on ) {
            foreach ( array_chunk( $urls, 30 ) as $chunk ) {
                $mobile = array_map(
                    static function ( string $u ): array {
                        return array(
                            'url'     => $u,
                            'headers' => array( 'CF-Device-Type' => 'mobile' ),
                        );
                    },
                    $chunk
                );
                $runtime['client']->zonePurgeFiles( $runtime['zone_tag'], $mobile );
            }
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_purge_all' ) ) {
    function nppp_cloudflare_apo_purge_all(): void {
        if ( ! nppp_cloudflare_apo_is_available() ) {
            return;
        }

        if ( ! apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_all' ) ) {
            return;
        }

        $hooks = nppp_cloudflare_apo_get_hooks();
        if ( $hooks ) {
            $GLOBALS['NPPP_CF_APO_PURGE_ALL_RAN'] = true;
            $GLOBALS['NPPP_CF_APO_URL_QUEUE']    = array();
            $hooks->purgeCacheEverything(); // CF plugin checks APO/PSC enabled internally.
        }
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_purge_urls' ) ) {
    function nppp_cloudflare_apo_purge_urls( array $urls, string $primary_url = '', int $post_id = 0, bool $is_auto = false ): void {
        if ( ! nppp_cloudflare_apo_is_available() ) {
            return;
        }

        if ( ! apply_filters( 'nppp_sync_cloudflare_apo_enabled', true, 'purge_urls', $urls, $primary_url, $post_id, $is_auto ) ) {
            return;
        }

        // Queue + flush once at shutdown for efficiency.
        nppp_cloudflare_apo_queue_urls( $urls );
    }
}

if ( ! function_exists( 'nppp_cloudflare_apo_sync_option_enabled' ) ) {
    function nppp_cloudflare_apo_sync_option_enabled(
        $enabled,
        string $context = '',
        $urls = null,
        string $primary_url = '',
        int $post_id = 0,
        bool $is_auto = false
    ): bool {
        if ( ! $enabled ) {
            return false;
        }

        $options = get_option( 'nginx_cache_settings', array() );
        $is_on   = isset( $options['nppp_cloudflare_apo_sync'] ) && $options['nppp_cloudflare_apo_sync'] === 'yes';

        if ( ! $is_on ) {
            return false;
        }

        // If enabled but CF plugin isn't available, force-disable to avoid misleading state.
        if ( ! nppp_cloudflare_apo_is_available() ) {
            $options['nppp_cloudflare_apo_sync'] = 'no';
            update_option( 'nginx_cache_settings', $options );
            return false;
        }

        return true;
    }
}

add_filter( 'nppp_sync_cloudflare_apo_enabled', 'nppp_cloudflare_apo_sync_option_enabled', 10, 6 );
add_action( 'nppp_purged_all', 'nppp_cloudflare_apo_purge_all' );
add_action( 'nppp_purged_urls', 'nppp_cloudflare_apo_purge_urls', 10, 4 );
