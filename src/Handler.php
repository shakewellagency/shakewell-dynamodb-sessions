<?php
/**
 * A PHP session handler backed by DynamoDB.
 *
 * @package Shakewell\DynamoDbSessions
 */

namespace Shakewell\DynamoDbSessions;

defined( 'ABSPATH' ) || exit;

/**
 * Stores each session as one item: id (string key), data (string) and expires (number, epoch seconds).
 *
 * It signs its own requests (AWS Signature Version 4) with the default credential chain's two sources: the
 * AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and AWS_SESSION_TOKEN environment variables, then the EC2 instance role
 * through IMDSv2. It does not use the AWS SDK and does not go through WordPress's HTTP API or proxy settings.
 *
 * If DynamoDB cannot be used, the first failure is logged and the rest of the request uses PHP's own file handler.
 */
final class Handler implements \SessionHandlerInterface {

	/**
	 * Sends one HTTP request: (method, url, headers, body, timeout) returns array{status:int,body:string|false}.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Credentials, looked up once per request.
	 *
	 * @var array{key:string,secret:string,token:string}|null
	 */
	private $creds = null;

	/**
	 * PHP's own handler, set after the first DynamoDB failure.
	 *
	 * @var \SessionHandler|null
	 */
	private $files = null;

	/**
	 * Save path from open().
	 *
	 * @var string
	 */
	private $path = '';

	/**
	 * Session name from open().
	 *
	 * @var string
	 */
	private $name = '';

	/**
	 * Host header value, with the port when there is one.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Request URL.
	 *
	 * @var string
	 */
	private $endpoint;

	/**
	 * Sets up the handler.
	 *
	 * @param string        $region    AWS region, already validated.
	 * @param string        $table     Table name, already validated.
	 * @param int           $lifetime  Seconds a session lives after its last write.
	 * @param string        $endpoint  Optional endpoint override, for example a local DynamoDB. Empty for AWS.
	 * @param callable|null $transport Replaces curl; tests only.
	 */
	public function __construct(
		private string $region,
		private string $table,
		private int $lifetime = 86400,
		string $endpoint = '',
		?callable $transport = null
	) {
		if ( '' === $endpoint ) {
			$endpoint = 'https://dynamodb.' . $region . '.amazonaws.com/'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- the DynamoDB API host requests are signed for, not an offloaded asset.
		}
		$parts           = wp_parse_url( $endpoint );
		$this->host      = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$this->endpoint  = $parts['scheme'] . '://' . $this->host . '/';
		$this->transport = $transport ?? array( $this, 'curl' );
	}

	/**
	 * Remembers the save path and name for the fall-back.
	 *
	 * @param string $path Save path.
	 * @param string $name Session name.
	 */
	public function open( $path, $name ): bool {
		$this->path = (string) $path;
		$this->name = (string) $name;
		return true;
	}

	/** Nothing to release. */
	public function close(): bool {
		return null === $this->files || $this->files->close();
	}

	/**
	 * Reads a session.
	 *
	 * @param string $id Session id.
	 * @return string The stored data, empty when there is none.
	 */
	#[\ReturnTypeWillChange]
	public function read( $id ) {
		if ( null === $this->files ) {
			try {
				$res = $this->call(
					'GetItem',
					array(
						'TableName'      => $this->table,
						'Key'            => array( 'id' => array( 'S' => (string) $id ) ),
						'ConsistentRead' => true,
					)
				);
				// An expired item can linger until the table's TTL sweep removes it, so check the clock.
				if ( isset( $res['Item']['data']['S'] ) && (int) ( $res['Item']['expires']['N'] ?? 0 ) > time() ) {
					return (string) $res['Item']['data']['S'];
				}
				return '';
			} catch ( \Throwable $e ) {
				$this->fall_back( 'read', $e );
			}
		}
		return $this->files->read( $id );
	}

	/**
	 * Writes a session.
	 *
	 * @param string $id   Session id.
	 * @param string $data Serialised session data.
	 */
	public function write( $id, $data ): bool {
		if ( null === $this->files ) {
			try {
				$this->call(
					'PutItem',
					array(
						'TableName' => $this->table,
						'Item'      => array(
							'id'      => array( 'S' => (string) $id ),
							'data'    => array( 'S' => (string) $data ),
							'expires' => array( 'N' => (string) ( time() + $this->lifetime ) ),
						),
					)
				);
				return true;
			} catch ( \Throwable $e ) {
				$this->fall_back( 'write', $e );
			}
		}
		return $this->files->write( $id, $data );
	}

	/**
	 * Deletes a session.
	 *
	 * @param string $id Session id.
	 */
	public function destroy( $id ): bool {
		if ( null === $this->files ) {
			try {
				$this->call(
					'DeleteItem',
					array(
						'TableName' => $this->table,
						'Key'       => array( 'id' => array( 'S' => (string) $id ) ),
					)
				);
				return true;
			} catch ( \Throwable $e ) {
				$this->fall_back( 'destroy', $e );
			}
		}
		return $this->files->destroy( $id );
	}

	/**
	 * The table's TTL on "expires" removes stale sessions, so there is nothing to collect.
	 *
	 * @param int $max_lifetime Ignored.
	 */
	#[\ReturnTypeWillChange]
	public function gc( $max_lifetime ) {
		return 0;
	}

	/** Whether this request has dropped to PHP's own handler. */
	public function is_falling_back(): bool {
		return null !== $this->files;
	}

	/**
	 * Logs the failure once and hands the rest of the request to PHP's file handler.
	 *
	 * @param string     $operation What was being done.
	 * @param \Throwable $e         Why it failed.
	 */
	private function fall_back( string $operation, \Throwable $e ): void {
		Plugin::log( 'DynamoDB session ' . $operation . ' failed, using PHP\'s default handler for this request: ' . $e->getMessage() );
		$this->files = new \SessionHandler();
		$this->files->open( $this->path, $this->name );
	}

	/**
	 * Performs one signed DynamoDB call.
	 *
	 * @param string $operation GetItem, PutItem or DeleteItem.
	 * @param array  $payload   Request body.
	 * @return array Decoded response.
	 * @throws \RuntimeException On any non-2xx answer.
	 */
	private function call( string $operation, array $payload ): array {
		$c      = $this->credentials();
		$body   = wp_json_encode( $payload );
		$target = 'DynamoDB_20120810.' . $operation;
		$ctype  = 'application/x-amz-json-1.0';

		$amz_date   = gmdate( 'Ymd\THis\Z' );
		$date_stamp = gmdate( 'Ymd' );

		$headers = array(
			'content-type' => $ctype,
			'host'         => $this->host,
			'x-amz-date'   => $amz_date,
			'x-amz-target' => $target,
		);
		if ( '' !== $c['token'] ) {
			$headers['x-amz-security-token'] = $c['token'];
		}
		ksort( $headers );
		$signed_headers    = implode( ';', array_keys( $headers ) );
		$canonical_headers = '';
		foreach ( $headers as $k => $v ) {
			$canonical_headers .= $k . ':' . $v . "\n";
		}

		$canonical_request = "POST\n/\n\n" . $canonical_headers . "\n" . $signed_headers . "\n" . hash( 'sha256', $body );
		$scope             = $date_stamp . '/' . $this->region . '/dynamodb/aws4_request';
		$string_to_sign    = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $c['secret'], true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 'dynamodb', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$http_headers = array(
			'Content-Type: ' . $ctype,
			'X-Amz-Date: ' . $amz_date,
			'X-Amz-Target: ' . $target,
			'Authorization: AWS4-HMAC-SHA256 Credential=' . $c['key'] . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
		);
		if ( '' !== $c['token'] ) {
			$http_headers[] = 'X-Amz-Security-Token: ' . $c['token'];
		}

		$resp = ( $this->transport )( 'POST', $this->endpoint, $http_headers, $body, 3 );
		if ( $resp['status'] < 200 || $resp['status'] >= 300 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught and written to the error log, never output.
			throw new \RuntimeException( $operation . ' returned HTTP ' . $resp['status'] . ': ' . substr( (string) $resp['body'], 0, 300 ) );
		}
		$decoded = json_decode( (string) $resp['body'], true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Credentials from the environment, else the EC2 instance role via IMDSv2.
	 *
	 * @return array{key:string,secret:string,token:string}
	 * @throws \RuntimeException When neither source has any.
	 */
	private function credentials(): array {
		if ( null !== $this->creds ) {
			return $this->creds;
		}
		$env_key    = getenv( 'AWS_ACCESS_KEY_ID' );
		$env_secret = getenv( 'AWS_SECRET_ACCESS_KEY' );
		if ( $env_key && $env_secret ) {
			$this->creds = array(
				'key'    => $env_key,
				'secret' => $env_secret,
				'token'  => (string) getenv( 'AWS_SESSION_TOKEN' ),
			);
			return $this->creds;
		}

		$imds = 'http://169.254.169.254/latest';
		$tok  = ( $this->transport )( 'PUT', $imds . '/api/token', array( 'X-aws-ec2-metadata-token-ttl-seconds: 21600' ), null, 1 );
		$auth = ( 200 === $tok['status'] && $tok['body'] ) ? array( 'X-aws-ec2-metadata-token: ' . trim( (string) $tok['body'] ) ) : array();
		$role = ( $this->transport )( 'GET', $imds . '/meta-data/iam/security-credentials/', $auth, null, 1 );
		if ( 200 !== $role['status'] || ! $role['body'] ) {
			throw new \RuntimeException( 'no AWS credentials in the environment and the instance role lookup failed' );
		}
		$cred = ( $this->transport )( 'GET', $imds . '/meta-data/iam/security-credentials/' . rawurlencode( trim( (string) $role['body'] ) ), $auth, null, 1 );
		$data = json_decode( (string) $cred['body'], true );
		if ( empty( $data['AccessKeyId'] ) || empty( $data['SecretAccessKey'] ) ) {
			throw new \RuntimeException( 'the instance role returned no credentials' );
		}
		$this->creds = array(
			'key'    => $data['AccessKeyId'],
			'secret' => $data['SecretAccessKey'],
			'token'  => $data['Token'] ?? '',
		);
		return $this->creds;
	}

	/**
	 * The default transport. Plain curl on purpose: WordPress's HTTP API may route through a proxy, and the metadata
	 * address must never go through one.
	 *
	 * @param string      $method  HTTP method.
	 * @param string      $url     Full URL.
	 * @param string[]    $headers Header lines.
	 * @param string|null $body    Request body.
	 * @param int         $timeout Seconds, for connect and total.
	 * @return array{status:int,body:string|false}
	 */
	private function curl( string $method, string $url, array $headers, ?string $body, int $timeout ): array {
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- see the method comment.
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => $timeout,
			)
		);
		if ( null !== $body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
		$out    = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		// phpcs:enable
		return array(
			'status' => $status,
			'body'   => $out,
		);
	}
}
