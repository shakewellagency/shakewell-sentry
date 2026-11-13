<?php
/**
 * Behaviour tests. A fake transport collects events, so nothing leaves the test container.
 *
 * @package Shakewell\Sentry
 */

namespace Shakewell\Sentry\Tests;

use ErrorException;
use Sentry\Event;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\Result;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\TransportInterface;
use Sentry\UserDataBag;
use Shakewell\Sentry\Plugin;
use WP_UnitTestCase;

/**
 * Fake transport: remembers every event it is given.
 */
final class FakeTransport implements TransportInterface {
	/**
	 * Captured events.
	 *
	 * @var Event[]
	 */
	public array $events = array();

	public function send( Event $event ): Result {
		$this->events[] = $event;
		return new Result( ResultStatus::success(), $event );
	}

	public function close( ?int $timeout = null ): Result {
		return new Result( ResultStatus::success() );
	}
}

final class PluginTest extends WP_UnitTestCase {

	private const VARS = array( 'SHAKEWELL_SENTRY_DSN', 'SHAKEWELL_SENTRY_ENVIRONMENT', 'SHAKEWELL_SENTRY_RELEASE', 'SHAKEWELL_SENTRY_SAMPLE_RATE', 'SHAKEWELL_SENTRY_SKIP_PLUGIN_NOTICES', 'WP_SENTRY_PHP_DSN', 'WP_SENTRY_ENV', 'WP_SENTRY_VERSION' );

	private FakeTransport $transport;

	public function set_up(): void {
		parent::set_up();
		foreach ( self::VARS as $name ) {
			putenv( $name );
		}
		$this->transport = new FakeTransport();
		// Keep the SDK from installing PHP error handlers inside the PHPUnit process.
		add_filter(
			'shakewell_sentry_options',
			static function ( array $options ) {
				$options['default_integrations'] = false;
				return $options;
			}
		);
	}

	public function tear_down(): void {
		foreach ( self::VARS as $name ) {
			putenv( $name );
		}
		SentrySdk::init()->bindClient( new \Sentry\Client( new \Sentry\Options(), new FakeTransport() ) );
		parent::tear_down();
	}

	private function start( array $env = array() ): bool {
		putenv( 'SHAKEWELL_SENTRY_DSN=https://key@example.invalid/1' );
		foreach ( $env as $name => $value ) {
			putenv( "$name=$value" );
		}
		return Plugin::init( $this->transport );
	}

	public function test_the_missing_sdk_notice_is_dismissible_and_says_how_to_fix_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::sdk_notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'composer install --no-dev', $html );

		wp_set_current_user( 0 );
		ob_start();
		Plugin::sdk_notice();
		$this->assertSame( '', (string) ob_get_clean(), 'visitors see nothing' );
	}

	public function test_off_without_dsn(): void {
		$this->assertFalse( Plugin::init( $this->transport ) );
		\Sentry\captureMessage( 'x' );
		$this->assertSame( array(), $this->transport->events );
	}

	public function test_constants_beat_wp_sentry_constants_beat_environment(): void {
		$const = array(
			'SHAKEWELL_SENTRY_ENVIRONMENT' => 'shakewell-const',
			'WP_SENTRY_ENV'                => 'wp-const',
			'WP_SENTRY_VERSION'            => 'wp-const-version',
		);
		$env   = array(
			'SHAKEWELL_SENTRY_ENVIRONMENT' => 'shakewell-env',
			'SHAKEWELL_SENTRY_RELEASE'     => 'shakewell-env-release',
			'WP_SENTRY_ENV'                => 'wp-env',
			'WP_SENTRY_PHP_DSN'            => 'wp-env-dsn',
		);
		$this->assertSame( 'shakewell-const', Plugin::setting( 'environment', $const, $env ) );
		unset( $const['SHAKEWELL_SENTRY_ENVIRONMENT'] );
		$this->assertSame( 'wp-const', Plugin::setting( 'environment', $const, $env ) );
		$this->assertSame( 'wp-const-version', Plugin::setting( 'release', $const, $env ), 'wp-sentry constant beats Shakewell env var' );
		$this->assertSame( 'wp-env-dsn', Plugin::setting( 'dsn', array(), $env ), 'wp-sentry env var is the last fallback' );
		$this->assertSame( 'shakewell-env-release', Plugin::setting( 'release', array(), $env ) );
		$this->assertNull( Plugin::setting( 'sample_rate', array(), array() ) );
	}

	public function test_exception_and_error_are_captured_with_environment_and_release(): void {
		$this->assertTrue( $this->start( array( 'SHAKEWELL_SENTRY_ENVIRONMENT' => 'staging', 'SHAKEWELL_SENTRY_RELEASE' => '1.2.3' ) ) );

		\Sentry\captureException( new \RuntimeException( 'boom' ) );
		\Sentry\captureException( new ErrorException( 'warned', 0, E_WARNING, __FILE__, __LINE__ ) );

		$this->assertCount( 2, $this->transport->events );
		foreach ( $this->transport->events as $event ) {
			$this->assertSame( 'staging', $event->getEnvironment() );
			$this->assertSame( '1.2.3', $event->getRelease() );
			$tags = $event->getTags();
			$this->assertSame( $GLOBALS['wp_version'], $tags['wordpress_version'] );
			$this->assertSame( PHP_VERSION, $tags['php_version'] );
			$this->assertSame( 'no', $tags['multisite'] );
		}
		$this->assertSame( 'boom', $this->transport->events[0]->getExceptions()[0]->getValue() );
		$this->assertSame( 'warned', $this->transport->events[1]->getExceptions()[0]->getValue() );
	}

	public function test_secrets_are_scrubbed_and_no_pii_by_default(): void {
		$this->start();
		$event = Event::createEvent();
		$event->setRequest(
			array(
				'url'          => 'https://example.test/checkout?token=querysecret&page=2',
				'method'       => 'POST',
				'query_string' => 'token=querysecret&page=2',
				'cookies'      => array( 'wordpress_logged_in' => 'cookiesecret' ),
				'data'         => array( 'pwd' => 'bodysecret' ),
				'headers'      => array(
					'Authorization' => 'Bearer topsecret',
					'Cookie'        => 'a=cookiesecret',
					'X-Api-Key'     => 'keysecret',
					'User-Agent'    => 'curl',
				),
			)
		);
		$event->setExtra( array( 'form' => array( 'user_pass' => 'hunter2', 'nested' => array( 'api_key' => 'k', 'ok' => 'fine' ) ) ) );
		$event->setUser( UserDataBag::createFromArray( array( 'id' => '5', 'email' => 'a@example.com', 'ip_address' => '203.0.113.9', 'username' => 'bob' ) ) );
		$event->setBreadcrumb( array( new \Sentry\Breadcrumb( 'info', 'default', 'http', 'call', array( 'password' => 'crumbsecret', 'status' => 200 ) ) ) );

		$out  = Plugin::before_send( $event );
		$json = wp_json_encode( ( new PayloadSerializer( new Options() ) )->serialize( $out ) ) . wp_json_encode( $out->getRequest() ) . wp_json_encode( $out->getBreadcrumbs() );
		foreach ( array( 'cookiesecret', 'topsecret', 'keysecret', 'querysecret', 'bodysecret', 'hunter2', 'crumbsecret', 'a@example.com', '203.0.113.9', 'bob' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $json, $leak );
		}
		$request = $out->getRequest();
		$this->assertSame( 'https://example.test/checkout', $request['url'] );
		$this->assertSame( 'curl', $request['headers']['User-Agent'] );
		$this->assertSame( '[Filtered]', $out->getExtra()['form']['user_pass'] );
		$this->assertSame( '[Filtered]', $out->getExtra()['form']['nested']['api_key'] );
		$this->assertSame( 'fine', $out->getExtra()['form']['nested']['ok'] );
		$this->assertNull( $out->getUser() );
	}

	public function test_user_id_only_when_filter_allows(): void {
		$this->start();
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'private@example.com' ) ) );
		$id = get_current_user_id();

		\Sentry\captureMessage( 'without filter' );
		$this->assertNull( $this->transport->events[0]->getUser() );

		add_filter( 'shakewell_sentry_send_user_id', '__return_true' );
		\Sentry\captureMessage( 'with filter' );
		$user = $this->transport->events[1]->getUser();
		$this->assertSame( (string) $id, $user->getId() );
		$this->assertNull( $user->getEmail() );
		$this->assertNull( $user->getUsername() );
		$this->assertNull( $user->getIpAddress() );
	}

	public function test_before_send_filter_can_drop_events(): void {
		$this->start();
		add_filter( 'shakewell_sentry_before_send', '__return_null' );
		\Sentry\captureMessage( 'dropped' );
		$this->assertSame( array(), $this->transport->events );
	}

	private function captured_after_error( int $severity, string $file, array $env = array( 'SHAKEWELL_SENTRY_SKIP_PLUGIN_NOTICES' => 'true' ) ): int {
		$this->start( $env );
		\Sentry\captureException( new ErrorException( 'e', 0, $severity, $file, 1 ) );
		return count( $this->transport->events );
	}

	public function test_plugin_notices_are_kept_unless_the_option_is_on(): void {
		$file = WP_PLUGIN_DIR . '/other-plugin/a.php';
		$this->assertSame( 1, $this->captured_after_error( E_WARNING, $file, array() ) );
	}

	public function test_third_party_plugin_notices_are_dropped_when_on(): void {
		$plugin = WP_PLUGIN_DIR . '/other-plugin/a.php';
		foreach ( array( E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED ) as $severity ) {
			$this->setUp_fresh();
			$this->assertSame( 0, $this->captured_after_error( $severity, $plugin ), "severity $severity" );
		}
	}

	public function test_fatal_errors_and_other_code_are_never_dropped(): void {
		$cases = array(
			'fatal in plugin'       => array( E_ERROR, WP_PLUGIN_DIR . '/other-plugin/a.php' ),
			'user error in plugin'  => array( E_USER_ERROR, WP_PLUGIN_DIR . '/other-plugin/a.php' ),
			'first-party plugin'    => array( E_WARNING, WP_PLUGIN_DIR . '/shakewell-thing/a.php' ),
			'theme'                 => array( E_WARNING, WP_CONTENT_DIR . '/themes/t/a.php' ),
			'core'                  => array( E_WARNING, ABSPATH . 'wp-includes/a.php' ),
			'plugin name prefix'    => array( E_WARNING, WP_PLUGIN_DIR . '-other/a.php' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->setUp_fresh();
			$this->assertSame( 1, $this->captured_after_error( $case[0], $case[1] ), $name );
		}
	}

	public function test_a_plain_exception_in_plugin_code_is_never_dropped(): void {
		$this->start( array( 'SHAKEWELL_SENTRY_SKIP_PLUGIN_NOTICES' => 'true' ) );
		\Sentry\captureException( new \RuntimeException( 'boom' ) );
		$this->assertCount( 1, $this->transport->events );
	}

	public function test_the_filter_changes_what_counts_as_third_party(): void {
		add_filter( 'shakewell_sentry_third_party_paths', static fn() => array( '/vendor-code/' ) );
		$this->assertSame( 0, $this->captured_after_error( E_WARNING, '/vendor-code/x.php' ) );
		$this->setUp_fresh();
		$this->assertSame( 1, $this->captured_after_error( E_WARNING, WP_PLUGIN_DIR . '/other-plugin/a.php' ) );
	}

	private function setUp_fresh(): void {
		$this->transport = new FakeTransport();
	}
}
