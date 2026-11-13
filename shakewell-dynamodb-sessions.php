<?php
/**
 * Plugin Name:       Shakewell DynamoDB Sessions
 * Plugin URI:        https://github.com/shakewellagency/shakewell-dynamodb-sessions
 * Description:       Stores PHP sessions in a DynamoDB table so load-balanced sites share them. Needs a one-line must-use loader; see the readme.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-dynamodb-sessions
 *
 * @package Shakewell\DynamoDbSessions
 */

namespace Shakewell\DynamoDbSessions;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

// Loaded twice (loader plus activation)? Register once.
if ( defined( __NAMESPACE__ . '\\LOADED' ) ) {
	return;
}
const LOADED = true;

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Handler.php';
require_once __DIR__ . '/src/Plugin.php';

// Not on a hook: the save handler must exist before any code calls session_start().
Plugin::boot();
