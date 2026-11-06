<?php
/**
 * Starts the Sentry PHP SDK from constants or environment variables.
 *
 * @package Shakewell\Sentry
 */

namespace Shakewell\Sentry;

use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Sentry\Transport\TransportInterface;
use Sentry\UserDataBag;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration lookup, SDK start and the event scrubber. No settings screen, no stored data.
 */
final class Plugin {

	/**
	 * Setting name => constants to look for, in order. The wp-sentry names are accepted so a site can switch without renaming.
	 */
	private const NAMES = array(
		'dsn'         => array( 'SHAKEWELL_SENTRY_DSN', 'WP_SENTRY_PHP_DSN' ),
		'environment' => array( 'SHAKEWELL_SENTRY_ENVIRONMENT', 'WP_SENTRY_ENV' ),
		'release'     => array( 'SHAKEWELL_SENTRY_RELEASE', 'WP_SENTRY_VERSION' ),
		'sample_rate' => array( 'SHAKEWELL_SENTRY_SAMPLE_RATE' ),
	);

	/**
	 * Keys whose values are replaced with [Filtered] wherever they appear in an event.
	 */
	private const SECRET_KEY = '/pass|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|auth|credential|cookie|session|nonce|signature|bearer|csrf|otp/i';

	private const SECRET_HEADERS = array( 'cookie', 'set-cookie', 'authorization', 'proxy-authorization', 'x-api-key', 'x-auth-token', 'x-wp-nonce', 'x-csrf-token' );

	/**
	 * Returns one setting: constants first (Shakewell names, then wp-sentry names), then environment variables in the same order.
	 *
	 * @param string     $key       dsn, environment, release or sample_rate.
	 * @param array|null $constants Test seam: name => value instead of real constants.
	 * @param array|null $env       Test seam: name => value instead of real environment variables.
	 */
	public static function setting( string $key, ?array $constants = null, ?array $env = null ): ?string {
		foreach ( self::NAMES[ $key ] as $name ) {
			$value = null === $constants ? ( defined( $name ) ? constant( $name ) : null ) : ( $constants[ $name ] ?? null );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		foreach ( self::NAMES[ $key ] as $name ) {
			$value = null === $env ? getenv( $name ) : ( $env[ $name ] ?? false );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return null;
	}

	/**
	 * Starts the SDK. Returns false (and does nothing) without a DSN or without the SDK.
	 *
	 * @param TransportInterface|null $transport Test seam: replaces the HTTP transport.
	 */
	public static function init( ?TransportInterface $transport = null ): bool {
		$dsn = self::setting( 'dsn' );
		if ( null === $dsn ) {
			return false;
		}
		// Composer-managed sites (Bedrock) autoload the SDK from the root vendor folder; the release zip carries its own.
		if ( ! class_exists( ClientBuilder::class ) && is_file( __DIR__ . '/../vendor/autoload.php' ) ) {
			require_once __DIR__ . '/../vendor/autoload.php';
		}
		if ( ! class_exists( ClientBuilder::class ) ) {
			return false;
		}

		$options = array(
			'dsn'                   => $dsn,
			'environment'           => self::setting( 'environment' ),
			'release'               => self::setting( 'release' ),
			'send_default_pii'      => false,
			'max_request_body_size' => 'none',
			'before_send'           => array( self::class, 'before_send' ),
		);
		$rate    = self::setting( 'sample_rate' );
		if ( null !== $rate && is_numeric( $rate ) ) {
			$options['sample_rate'] = max( 0.0, min( 1.0, (float) $rate ) );
		}
		/**
		 * Filters the Sentry SDK options. Runs once at load, so register it from an mu-plugin or wp-config.php.
		 *
		 * @param array $options Options passed to Sentry\ClientBuilder.
		 */
		$options = apply_filters( 'shakewell_sentry_options', array_filter( $options, static fn( $v ) => null !== $v ) );

		$builder = ClientBuilder::create( $options );
		if ( $transport ) {
			$builder->setTransport( $transport );
		}
		$hub = SentrySdk::init();
		$hub->bindClient( $builder->getClient() );

		global $wp_version;
		$tags = array(
			'wordpress_version' => (string) ( $wp_version ?? 'unknown' ),
			'php_version'       => PHP_VERSION,
			'multisite'         => function_exists( 'is_multisite' ) && is_multisite() ? 'yes' : 'no',
		);
		$hub->configureScope( static fn( Scope $scope ) => $scope->setTags( $tags ) );
		return true;
	}

	/**
	 * Removes secrets from every event, applies the optional user id, then lets sites extend or drop the event.
	 *
	 * @param Event          $event The event about to be sent.
	 * @param EventHint|null $hint  The SDK hint.
	 */
	public static function before_send( Event $event, ?EventHint $hint = null ): ?Event {
		$request = $event->getRequest();
		unset( $request['cookies'], $request['data'] );
		if ( isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
			$request['headers'] = array_filter(
				$request['headers'],
				static fn( $name ) => ! in_array( strtolower( (string) $name ), self::SECRET_HEADERS, true ),
				ARRAY_FILTER_USE_KEY
			);
		}
		if ( ! empty( $request['query_string'] ) && is_string( $request['query_string'] ) ) {
			parse_str( $request['query_string'], $query );
			// Parameter names are useful, their values may be tokens: keep only the names.
			$request['query_string'] = http_build_query( array_map( static fn() => '[Filtered]', $query ) );
		}
		if ( ! empty( $request['url'] ) && is_string( $request['url'] ) ) {
			$request['url'] = strtok( $request['url'], '?#' );
		}
		$event->setRequest( self::redact( $request ) );
		$event->setExtra( self::redact( $event->getExtra() ) );
		$crumbs = array();
		foreach ( $event->getBreadcrumbs() as $crumb ) {
			foreach ( self::redact( $crumb->getMetadata() ) as $name => $value ) {
				$crumb = $crumb->withMetadata( (string) $name, $value );
			}
			$crumbs[] = $crumb;
		}
		$event->setBreadcrumb( $crumbs );

		$user_id = 0;
		/**
		 * Allows the logged-in user's numeric ID (never email, name or IP) on events. Off by default.
		 *
		 * @param bool $allow Whether to attach the user ID.
		 */
		if ( apply_filters( 'shakewell_sentry_send_user_id', false ) && function_exists( 'wp_get_current_user' ) ) {
			$user_id = (int) get_current_user_id();
		}
		$event->setUser( $user_id > 0 ? UserDataBag::createFromUserIdentifier( (string) $user_id ) : null );

		/**
		 * Filters the scrubbed event just before it is sent. Return null to drop it.
		 *
		 * @param Event|null     $event The event.
		 * @param EventHint|null $hint  The SDK hint (original exception, etc).
		 */
		return apply_filters( 'shakewell_sentry_before_send', $event, $hint );
	}

	/**
	 * Replaces the value of any secret-looking key, at any depth.
	 *
	 * @param array $data Data to clean.
	 */
	private static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SECRET_KEY, $key ) ) {
				$data[ $key ] = '[Filtered]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}
}
