<?php
/**
 * Settings from constants, environment variables and a filter.
 *
 * @package Shakewell\DynamoDbSessions
 */

namespace Shakewell\DynamoDbSessions;

defined( 'ABSPATH' ) || exit;

/**
 * Reads SHAKEWELL_DYNAMODB_SESSIONS_* settings. No credentials are read here.
 */
final class Config {

	/**
	 * A constant wins over an environment variable of the same name.
	 *
	 * @param string $name Setting name without the prefix, for example TABLE.
	 * @return string Empty when unset.
	 */
	public static function lookup( string $name ): string {
		$full = 'SHAKEWELL_DYNAMODB_SESSIONS_' . $name;
		if ( defined( $full ) ) {
			return trim( (string) constant( $full ) );
		}
		$env = getenv( $full );
		return false === $env ? '' : trim( $env );
	}

	/**
	 * Resolves and validates the settings.
	 *
	 * The filter `shakewell_dynamodb_sessions_config` can change the array. It only runs for callbacks added before this
	 * file loads, for example from a must-use plugin that sorts earlier.
	 *
	 * @return array{table:string,region:string,ttl:int,endpoint:string}|string The settings, or the reason they are unusable.
	 */
	public static function resolve() {
		$region = self::lookup( 'REGION' );
		if ( '' === $region ) {
			$region = (string) ( getenv( 'AWS_REGION' ) ? getenv( 'AWS_REGION' ) : getenv( 'AWS_DEFAULT_REGION' ) );
		}
		$ttl = self::lookup( 'TTL' );

		$config = array(
			'table'    => self::lookup( 'TABLE' ),
			'region'   => $region,
			'ttl'      => '' === $ttl ? 86400 : (int) $ttl,
			'endpoint' => self::lookup( 'ENDPOINT' ),
		);
		$config = apply_filters( 'shakewell_dynamodb_sessions_config', $config );

		if ( ! is_array( $config ) || ! isset( $config['table'], $config['region'], $config['ttl'], $config['endpoint'] ) ) {
			return 'the shakewell_dynamodb_sessions_config filter returned an unusable value';
		}
		$config['table']    = (string) $config['table'];
		$config['region']   = (string) $config['region'];
		$config['ttl']      = (int) $config['ttl'];
		$config['endpoint'] = (string) $config['endpoint'];

		// The region becomes part of a hostname, so accept only a real region name.
		if ( ! preg_match( '/^[a-z]{2}(-[a-z]+)+-\d$/', $config['region'] ) ) {
			return 'no valid region (set SHAKEWELL_DYNAMODB_SESSIONS_REGION or AWS_REGION)';
		}
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{3,255}$/', $config['table'] ) ) {
			return 'no valid table name (set SHAKEWELL_DYNAMODB_SESSIONS_TABLE)';
		}
		if ( $config['ttl'] < 60 ) {
			return 'the TTL must be at least 60 seconds';
		}
		if ( '' !== $config['endpoint'] ) {
			$parts = wp_parse_url( $config['endpoint'] );
			if ( empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
				return 'the endpoint must be an http or https URL';
			}
		}
		return $config;
	}
}
