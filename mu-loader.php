<?php
/**
 * Must-use loader for Shakewell DynamoDB Sessions.
 *
 * Copy this file to wp-content/mu-plugins/000-shakewell-dynamodb-sessions.php so sessions start in DynamoDB before any
 * plugin runs. It deliberately has no "Plugin Name" header: WordPress would list it as a second plugin.
 *
 * @package Shakewell\DynamoDbSessions
 */

defined( 'ABSPATH' ) || exit;

if ( is_readable( WP_PLUGIN_DIR . '/shakewell-dynamodb-sessions/shakewell-dynamodb-sessions.php' ) ) {
	require_once WP_PLUGIN_DIR . '/shakewell-dynamodb-sessions/shakewell-dynamodb-sessions.php';
}
