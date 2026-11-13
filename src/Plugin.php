<?php
/**
 * Registers the session handler.
 *
 * @package Shakewell\DynamoDbSessions
 */

namespace Shakewell\DynamoDbSessions;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the handler into PHP. Every failure leaves PHP's own session handler in place.
 */
final class Plugin {

	/**
	 * Registers the handler unless this is a command-line run, the settings are unusable or a session has already started.
	 *
	 * @return bool Whether the handler was registered.
	 */
	public static function boot(): bool {
		if ( 'cli' === PHP_SAPI ) {
			return false; // Leave wp-cli and other command-line runs alone.
		}

		$config = Config::resolve();
		if ( is_string( $config ) ) {
			self::log( 'not used, ' . $config . '; PHP\'s default session handler stays in place' );
			return false;
		}
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			self::log( 'loaded too late, a session has already started; load it from a must-use plugin' );
			return false;
		}

		$handler = new Handler( $config['region'], $config['table'], $config['ttl'], $config['endpoint'] );
		// Sessions expire through the table's TTL on "expires", so PHP's own garbage collection stays off.
		// phpcs:ignore WordPress.PHP.IniSet.Risky,Squiz.PHP.DiscouragedFunctions.Discouraged -- the handler owns session lifetime.
		ini_set( 'session.gc_probability', '0' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky,Squiz.PHP.DiscouragedFunctions.Discouraged
		ini_set( 'session.gc_maxlifetime', (string) $config['ttl'] );
		return session_set_save_handler( $handler, true );
	}

	/**
	 * Writes one line to the PHP error log.
	 *
	 * @param string $message What happened.
	 */
	public static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a session problem must reach the server log.
		error_log( '[shakewell-dynamodb-sessions] ' . $message );
	}
}
