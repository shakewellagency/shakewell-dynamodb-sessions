=== Shakewell DynamoDB Sessions ===
Contributors: shakewellagency
Tags: sessions, dynamodb, aws, load balancing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stores PHP sessions in an Amazon DynamoDB table so load-balanced sites share them. Falls back to PHP's own handler if DynamoDB fails.

== Description ==

Shakewell DynamoDB Sessions registers a PHP session handler that keeps each session in one DynamoDB item, so every server behind a load balancer sees the same session and no session files sit on local disk. It signs its own requests with the environment keys or the EC2 instance role, has no AWS SDK and no settings screen.

It must load before any code starts a session, so load it as a must-use plugin with the one-line loader (`mu-loader.php`) described in the repository readme. Configure it with the `SHAKEWELL_DYNAMODB_SESSIONS_TABLE`, `_REGION`, `_TTL` and `_ENDPOINT` constants or environment variables.

If it is not configured, or DynamoDB cannot be used, PHP's default handler is used and one line is written to the error log. It does nothing on the command line.

Not affiliated with, endorsed by or sponsored by Amazon.

= External services =

Amazon DynamoDB in the region you configure, and the EC2 instance metadata service when no keys are in the environment. It sends each session's id and data, signed, whenever a request uses a session. Nothing is sent until a table is configured. Amazon service terms: https://aws.amazon.com/service-terms/ Privacy: https://aws.amazon.com/privacy/

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer. Do not activate it.
2. Copy `mu-loader.php` to `/wp-content/mu-plugins/000-shakewell-dynamodb-sessions.php`.
3. Set `SHAKEWELL_DYNAMODB_SESSIONS_TABLE` and a region (`SHAKEWELL_DYNAMODB_SESSIONS_REGION` or `AWS_REGION`).

== Frequently Asked Questions ==

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release.
