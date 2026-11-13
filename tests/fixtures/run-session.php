<?php
/**
 * Runs one real PHP session through the handler with a failing transport, outside WordPress.
 *
 * Usage: php run-session.php <save path> <session id>. Prints the counter after this request.
 * WordPress cannot do this in-process because PHPUnit has already sent output.
 *
 * @package Shakewell\DynamoDbSessions
 */

define( 'ABSPATH', '/' );
// phpcs:disable
function wp_parse_url( $url ) {
	return parse_url( $url );
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function apply_filters( $tag, $value ) {
	return $value;
}
// phpcs:enable

require dirname( __DIR__, 2 ) . '/src/Config.php';
require dirname( __DIR__, 2 ) . '/src/Handler.php';
require dirname( __DIR__, 2 ) . '/src/Plugin.php';

ini_set( 'session.use_cookies', '0' );
session_cache_limiter( '' );
session_save_path( $argv[1] );

$handler = new Shakewell\DynamoDbSessions\Handler(
	'eu-west-2',
	'app-sessions',
	600,
	'',
	static function () {
		return array(
			'status' => 500,
			'body'   => 'boom',
		);
	}
);
session_set_save_handler( $handler, true );
session_id( $argv[2] );
session_start();
$_SESSION['n'] = ( $_SESSION['n'] ?? 0 ) + 1;
echo $_SESSION['n'], ' ', var_export( $handler->is_falling_back(), true );
session_write_close();
