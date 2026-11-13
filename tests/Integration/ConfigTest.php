<?php
/**
 * Settings resolution.
 *
 * @package Shakewell\DynamoDbSessions
 */

namespace Shakewell\DynamoDbSessions\Tests;

use Shakewell\DynamoDbSessions\Config;
use WP_UnitTestCase;

/**
 * Covers constants, environment, validation and the filter.
 */
final class ConfigTest extends WP_UnitTestCase {

	private const VARS = array(
		'SHAKEWELL_DYNAMODB_SESSIONS_TABLE',
		'SHAKEWELL_DYNAMODB_SESSIONS_REGION',
		'SHAKEWELL_DYNAMODB_SESSIONS_TTL',
		'SHAKEWELL_DYNAMODB_SESSIONS_ENDPOINT',
		'AWS_REGION',
		'AWS_DEFAULT_REGION',
	);

	public function set_up(): void {
		parent::set_up();
		foreach ( self::VARS as $var ) {
			putenv( $var );
		}
	}

	public function tear_down(): void {
		foreach ( self::VARS as $var ) {
			putenv( $var );
		}
		parent::tear_down();
	}

	public function test_unconfigured_is_unusable(): void {
		$this->assertIsString( Config::resolve() );
	}

	public function test_table_and_region_from_the_environment(): void {
		putenv( 'SHAKEWELL_DYNAMODB_SESSIONS_TABLE=app-sessions' );
		putenv( 'AWS_REGION=eu-west-2' );
		$this->assertSame(
			array(
				'table'    => 'app-sessions',
				'region'   => 'eu-west-2',
				'ttl'      => 86400,
				'endpoint' => '',
			),
			Config::resolve()
		);
	}

	public function test_own_region_setting_beats_aws_region(): void {
		putenv( 'SHAKEWELL_DYNAMODB_SESSIONS_TABLE=app-sessions' );
		putenv( 'SHAKEWELL_DYNAMODB_SESSIONS_REGION=us-east-1' );
		putenv( 'AWS_REGION=eu-west-2' );
		$this->assertSame( 'us-east-1', Config::resolve()['region'] );
	}

	public function test_aws_default_region_is_the_last_resort(): void {
		putenv( 'SHAKEWELL_DYNAMODB_SESSIONS_TABLE=app-sessions' );
		putenv( 'AWS_DEFAULT_REGION=ap-southeast-2' );
		$this->assertSame( 'ap-southeast-2', Config::resolve()['region'] );
	}

	public function test_a_constant_beats_the_environment(): void {
		putenv( 'SHAKEWELL_DYNAMODB_SESSIONS_PROBE=from-env' );
		$this->assertSame( 'from-env', Config::lookup( 'PROBE' ) );
		define( 'SHAKEWELL_DYNAMODB_SESSIONS_PROBE', 'from-constant' );
		$this->assertSame( 'from-constant', Config::lookup( 'PROBE' ) );
		putenv( 'SHAKEWELL_DYNAMODB_SESSIONS_PROBE' );
	}

	/**
	 * @dataProvider bad_values
	 *
	 * @param string $var   Setting to break.
	 * @param string $value Bad value.
	 */
	public function test_bad_values_are_rejected( string $var, string $value ): void {
		putenv( 'SHAKEWELL_DYNAMODB_SESSIONS_TABLE=app-sessions' );
		putenv( 'AWS_REGION=eu-west-2' );
		putenv( $var . '=' . $value );
		$this->assertIsString( Config::resolve() );
	}

	public static function bad_values(): array {
		return array(
			'region that is a hostname'    => array( 'AWS_REGION', 'evil.example/x' ),
			'region with a path'           => array( 'SHAKEWELL_DYNAMODB_SESSIONS_REGION', 'eu-west-2/..' ),
			'table with a space'           => array( 'SHAKEWELL_DYNAMODB_SESSIONS_TABLE', 'bad table' ),
			'table too short'              => array( 'SHAKEWELL_DYNAMODB_SESSIONS_TABLE', 'ab' ),
			'ttl too small'                => array( 'SHAKEWELL_DYNAMODB_SESSIONS_TTL', '5' ),
			'endpoint that is not http'    => array( 'SHAKEWELL_DYNAMODB_SESSIONS_ENDPOINT', 'file:///etc/passwd' ),
			'endpoint without a scheme'    => array( 'SHAKEWELL_DYNAMODB_SESSIONS_ENDPOINT', 'localhost:8000' ),
		);
	}

	public function test_the_filter_can_set_every_value(): void {
		$filter = static function ( array $config ): array {
			$config['table']  = 'from-filter';
			$config['region'] = 'eu-west-2';
			$config['ttl']    = 3600;
			return $config;
		};
		add_filter( 'shakewell_dynamodb_sessions_config', $filter );
		$config = Config::resolve();
		remove_filter( 'shakewell_dynamodb_sessions_config', $filter );
		$this->assertSame( 'from-filter', $config['table'] );
		$this->assertSame( 3600, $config['ttl'] );
	}

	public function test_a_broken_filter_result_is_unusable_not_fatal(): void {
		add_filter( 'shakewell_dynamodb_sessions_config', '__return_false' );
		$result = Config::resolve();
		remove_filter( 'shakewell_dynamodb_sessions_config', '__return_false' );
		$this->assertIsString( $result );
	}
}
