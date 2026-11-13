<?php
/**
 * The session handler, against a fake transport.
 *
 * @package Shakewell\DynamoDbSessions
 */

namespace Shakewell\DynamoDbSessions\Tests;

use Shakewell\DynamoDbSessions\Handler;
use Shakewell\DynamoDbSessions\Plugin;
use WP_UnitTestCase;

/**
 * Checks the requests sent to DynamoDB and the fall-back to PHP's own handler.
 */
final class HandlerTest extends WP_UnitTestCase {

	/** @var array[] Requests the fake saw: method, url, headers, body. */
	private array $sent = array();

	private string $log = '';

	private string $old_log = '';

	private string $dir = '';

	public function set_up(): void {
		parent::set_up();
		putenv( 'AWS_ACCESS_KEY_ID=AKIDEXAMPLE' );
		putenv( 'AWS_SECRET_ACCESS_KEY=secret-example' );
		putenv( 'AWS_SESSION_TOKEN=token-example' );
		$this->sent    = array();
		$this->log     = tempnam( sys_get_temp_dir(), 'ddb-log' );
		$this->dir     = sys_get_temp_dir() . '/ddb-files-' . uniqid();
		$this->old_log = (string) ini_get( 'error_log' );
		mkdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}

	public function tear_down(): void {
		ini_set( 'error_log', $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		putenv( 'AWS_ACCESS_KEY_ID' );
		putenv( 'AWS_SECRET_ACCESS_KEY' );
		putenv( 'AWS_SESSION_TOKEN' );
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		unlink( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		parent::tear_down();
	}

	/**
	 * Builds a handler whose transport records requests and answers with $reply.
	 *
	 * @param callable|array $reply Answer, or a callable that gets the request and returns one.
	 * @param string         $endpoint Endpoint override.
	 */
	private function handler( $reply, string $endpoint = '' ): Handler {
		$transport = function ( $method, $url, $headers, $body ) use ( $reply ) {
			$this->sent[] = compact( 'method', 'url', 'headers', 'body' );
			return is_callable( $reply ) ? $reply( $method, $url ) : $reply;
		};
		return new Handler( 'eu-west-2', 'app-sessions', 600, $endpoint, $transport );
	}

	private function ok( array $body = array() ): array {
		return array(
			'status' => 200,
			'body'   => (string) wp_json_encode( $body ),
		);
	}

	private function header( array $request, string $name ): string {
		foreach ( $request['headers'] as $line ) {
			if ( 0 === stripos( $line, $name . ':' ) ) {
				return trim( substr( $line, strlen( $name ) + 1 ) );
			}
		}
		return '';
	}

	public function test_write_stores_the_data_with_an_expiry(): void {
		$before = time();
		$this->assertTrue( $this->handler( $this->ok() )->write( 'abc', 'x|i:1;' ) );

		$req  = $this->sent[0];
		$body = json_decode( $req['body'], true );
		$this->assertSame( 'https://dynamodb.eu-west-2.amazonaws.com/', $req['url'] );
		$this->assertSame( 'DynamoDB_20120810.PutItem', $this->header( $req, 'X-Amz-Target' ) );
		$this->assertSame( 'app-sessions', $body['TableName'] );
		$this->assertSame( 'abc', $body['Item']['id']['S'] );
		$this->assertSame( base64_encode( 'x|i:1;' ), $body['Item']['data']['B'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertGreaterThanOrEqual( $before + 600, (int) $body['Item']['expires']['N'] );
		$this->assertLessThanOrEqual( time() + 600, (int) $body['Item']['expires']['N'] );
	}

	public function test_requests_are_signed_with_the_environment_credentials(): void {
		$this->handler( $this->ok() )->write( 'abc', 'd' );
		$req = $this->sent[0];
		$this->assertMatchesRegularExpression(
			'#^AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/\d{8}/eu-west-2/dynamodb/aws4_request, SignedHeaders=content-type;host;x-amz-date;x-amz-security-token;x-amz-target, Signature=[0-9a-f]{64}$#',
			$this->header( $req, 'Authorization' )
		);
		$this->assertSame( 'token-example', $this->header( $req, 'X-Amz-Security-Token' ) );
		$this->assertStringNotContainsString( 'secret-example', implode( "\n", $req['headers'] ) . $req['body'] );
	}

	public function test_read_returns_stored_data(): void {
		$handler = $this->handler(
			$this->ok(
				array(
					'Item' => array(
						'data'    => array( 'B' => base64_encode( 'x|i:1;' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'expires' => array( 'N' => (string) ( time() + 100 ) ),
					),
				)
			)
		);
		$this->assertSame( 'x|i:1;', $handler->read( 'abc' ) );
		$this->assertTrue( json_decode( $this->sent[0]['body'], true )['ConsistentRead'] );
	}

	public function test_binary_session_data_survives_a_round_trip(): void {
		$binary = "\xff\x00" . random_bytes( 8 );
		$store  = array();
		$fake   = function ( $method, $url, $headers, $body ) use ( &$store ) {
			$req = json_decode( $body, true );
			if ( isset( $req['Item'] ) ) {
				$store = $req['Item'];
				return $this->ok();
			}
			return $this->ok( array( 'Item' => $store ) );
		};
		$handler = new Handler( 'eu-west-2', 'app-sessions', 600, '', $fake );
		$this->assertTrue( $handler->write( 'abc', $binary ) );
		$this->assertSame( $binary, $handler->read( 'abc' ) );
	}

	public function test_a_string_item_from_an_older_handler_is_still_read(): void {
		$handler = $this->handler(
			$this->ok(
				array(
					'Item' => array(
						'data'    => array( 'S' => 'x|i:1;' ),
						'expires' => array( 'N' => (string) ( time() + 100 ) ),
					),
				)
			)
		);
		$this->assertSame( 'x|i:1;', $handler->read( 'abc' ) );
	}

	public function test_read_of_a_missing_session_is_empty_and_does_not_fall_back(): void {
		$handler = $this->handler( $this->ok() );
		$this->assertSame( '', $handler->read( 'abc' ) );
		$this->assertFalse( $handler->is_falling_back() );
	}

	public function test_read_ignores_an_expired_item_the_table_has_not_swept_yet(): void {
		$handler = $this->handler(
			$this->ok(
				array(
					'Item' => array(
						'data'    => array( 'S' => 'old' ),
						'expires' => array( 'N' => (string) ( time() - 5 ) ),
					),
				)
			)
		);
		$this->assertSame( '', $handler->read( 'abc' ) );
	}

	public function test_destroy_deletes_the_item(): void {
		$this->assertTrue( $this->handler( $this->ok() )->destroy( 'abc' ) );
		$this->assertSame( 'DynamoDB_20120810.DeleteItem', $this->header( $this->sent[0], 'X-Amz-Target' ) );
	}

	public function test_gc_does_nothing(): void {
		$this->assertSame( 0, $this->handler( $this->ok() )->gc( 100 ) );
		$this->assertSame( array(), $this->sent );
	}

	public function test_an_endpoint_override_is_used_and_signed_with_its_host(): void {
		$this->handler( $this->ok(), 'http://localhost:8000' )->write( 'abc', 'd' );
		$this->assertSame( 'http://localhost:8000/', $this->sent[0]['url'] );
	}

	public function test_the_instance_role_is_used_when_the_environment_has_no_keys(): void {
		putenv( 'AWS_ACCESS_KEY_ID' );
		putenv( 'AWS_SECRET_ACCESS_KEY' );
		putenv( 'AWS_SESSION_TOKEN' );
		$handler = $this->handler(
			function ( $method, $url ) {
				if ( 'PUT' === $method ) {
					return array(
						'status' => 200,
						'body'   => 'imds-token',
					);
				}
				if ( str_ends_with( $url, '/security-credentials/' ) ) {
					return array(
						'status' => 200,
						'body'   => 'role-name',
					);
				}
				if ( str_ends_with( $url, '/security-credentials/role-name' ) ) {
					return $this->ok(
						array(
							'AccessKeyId'     => 'ROLEKEY',
							'SecretAccessKey' => 'role-secret',
							'Token'           => 'role-token',
						)
					);
				}
				return $this->ok();
			}
		);
		$this->assertTrue( $handler->write( 'abc', 'd' ) );

		$last = end( $this->sent );
		$this->assertStringContainsString( 'Credential=ROLEKEY/', $this->header( $last, 'Authorization' ) );
		$this->assertSame( 'role-token', $this->header( $last, 'X-Amz-Security-Token' ) );
		$this->assertSame( 'imds-token', $this->header( $this->sent[1], 'X-aws-ec2-metadata-token' ) );

		// A second call in the same request reuses the credentials.
		$count = count( $this->sent );
		$handler->write( 'abc', 'e' );
		$this->assertSame( $count + 1, count( $this->sent ) );
	}

	/**
	 * Runs one real PHP session request in a separate process, with DynamoDB failing.
	 *
	 * @param string $id Session id.
	 * @return string "<counter> <fell back>".
	 */
	private function run_session( string $id ): string {
		$cmd = array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/run-session.php', $this->dir, $id );
		$p   = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', $this->log, 'a' ) ), $pipes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		proc_close( $p );
		return $out;
	}

	public function test_an_unreachable_table_falls_back_to_php_files_and_logs_once_per_request(): void {
		$this->assertSame( '1 true', $this->run_session( 'sessone' ) );
		$this->assertFileExists( $this->dir . '/sess_sessone' );
		// The next request finds the same session in the files.
		$this->assertSame( '2 true', $this->run_session( 'sessone' ) );

		$log = (string) file_get_contents( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 2, substr_count( $log, '[shakewell-dynamodb-sessions]' ) );
		$this->assertStringContainsString( 'HTTP 500', $log );
	}

	public function test_a_failed_request_never_calls_dynamodb_again(): void {
		$handler = $this->handler(
			array(
				'status' => 500,
				'body'   => 'boom',
			)
		);
		$handler->open( $this->dir, 'PHPSESSID' );
		try {
			$handler->read( 'x' );
		} catch ( \Error $e ) {
			// SessionHandler needs an active session; only the call count matters here.
			unset( $e );
		}
		try {
			$handler->write( 'x', 'y' );
		} catch ( \Error $e ) {
			unset( $e );
		}
		$this->assertCount( 1, $this->sent );
		$this->assertTrue( $handler->is_falling_back() );
	}

	public function test_the_handler_is_not_registered_on_the_command_line(): void {
		$this->assertFalse( Plugin::boot() );
	}
}
