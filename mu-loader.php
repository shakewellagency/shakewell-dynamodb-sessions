<?php
/**
 * Plugin Name: Shakewell DynamoDB Sessions loader
 * Description: Copy this file into wp-content/mu-plugins/ (as 000-shakewell-dynamodb-sessions.php) so sessions start in DynamoDB before any plugin runs.
 * Author: Shakewell
 *
 * @package Shakewell\DynamoDbSessions
 */

defined( 'ABSPATH' ) || exit;

if ( is_readable( WP_PLUGIN_DIR . '/shakewell-dynamodb-sessions/shakewell-dynamodb-sessions.php' ) ) {
	require_once WP_PLUGIN_DIR . '/shakewell-dynamodb-sessions/shakewell-dynamodb-sessions.php';
}
