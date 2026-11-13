# Shakewell DynamoDB Sessions

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

Stores PHP sessions in an Amazon DynamoDB table, so every server behind a load balancer sees the same session and no session files sit on local disk. One job, no AWS SDK, no admin screen.

Shakewell DynamoDB Sessions is not affiliated with, endorsed by or sponsored by Amazon. Amazon DynamoDB is a trademark of Amazon.com, Inc. or its affiliates.

## How it works

On load it registers a PHP session save handler. Each session is one table item: `id` (string, the table's partition key), `data` and `expires` (epoch seconds). Requests are signed with AWS Signature Version 4 using the credentials in the environment or the EC2 instance role (IMDSv2). It needs the PHP `curl` extension and nothing from Composer at run time.

It does not use WordPress's HTTP API, so WordPress proxy settings are never applied to DynamoDB or instance-metadata calls.

## Where it must load: as a must-use plugin

The handler has to be registered before anything calls `session_start()`. Plugins that use `$_SESSION` often start sessions as soon as they load, so a normal plugin is too late for some of them. Load this one as a must-use plugin:

1. Install the plugin folder as `shakewell-dynamodb-sessions` in the plugins directory. Leave it deactivated.
2. Copy `mu-loader.php` from the plugin folder to the must-use plugins directory as `000-shakewell-dynamodb-sessions.php`. WordPress loads must-use plugins in file name order, before regular plugins, and `000-` puts it first.

The loader is one `require_once` of the plugin's main file, which registers the handler immediately (not on a hook). If you only activate the plugin instead, it still works, but any plugin loaded before it that starts a session wins, and the handler then logs `loaded too late` and steps aside. Sessions never start earlier or later than before: the handler only changes where they are stored.

It does nothing on the command line (wp-cli and cron runs from the CLI use PHP's own handler).

## Settings

Each setting is a constant (in `wp-config.php`) or an environment variable of the same name. A constant wins.

| Name | Required | Default | Meaning |
|---|---|---|---|
| `SHAKEWELL_DYNAMODB_SESSIONS_TABLE` | yes | none | Table name. |
| `SHAKEWELL_DYNAMODB_SESSIONS_REGION` | no | `AWS_REGION`, then `AWS_DEFAULT_REGION` | AWS region, for example `eu-west-2`. One of these must be set. |
| `SHAKEWELL_DYNAMODB_SESSIONS_TTL` | no | `86400` | Seconds a session lives after its last write. Minimum 60. |
| `SHAKEWELL_DYNAMODB_SESSIONS_ENDPOINT` | no | AWS | Endpoint override, for example `http://localhost:8000` for DynamoDB Local. |

The filter `shakewell_dynamodb_sessions_config` receives the resolved array (`table`, `region`, `ttl`, `endpoint`) and returns it. The handler registers while WordPress is still loading must-use plugins, so only a callback added before this file loads runs, for example from a must-use plugin that sorts before `000-`.

There is no setting for credentials. Keys are never read from constants. The handler uses `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `AWS_SESSION_TOKEN` from the environment if present (local development), otherwise the instance role. Use the instance role in production.

There is no session locking: two simultaneous requests for one session can overwrite each other's changes. The last write wins, as with PHP files that are not locked.

## The table

```
partition key: id (String)
TTL attribute: expires
```

On-demand capacity is enough. The table's TTL deletes expired sessions (it can lag by hours, so the handler also ignores any item whose `expires` has passed). The role needs `dynamodb:GetItem`, `dynamodb:PutItem` and `dynamodb:DeleteItem` on the table.

## When something is wrong

The site never stops because of this plugin.

- Unconfigured or invalid settings (no table, bad region, TTL under 60, bad endpoint): the handler is not registered, PHP's default handler stays, and one line goes to the PHP error log: `[shakewell-dynamodb-sessions] not used, ...`.
- DynamoDB unreachable, a credentials failure or an error answer: the first failure is logged, and the rest of that request uses PHP's file handler. The next request tries DynamoDB again. While it is down, sessions are per server, so users can be signed out when the balancer moves them. Each failing request can wait up to three seconds on the network. Logging is once per request, not once per outage.

## External services

Amazon DynamoDB in the region you configure (or the endpoint you set), and the EC2 instance metadata service at 169.254.169.254 when no keys are in the environment. Sent: the session id and session data of every request that uses a session, plus the signed request. Nothing is sent anywhere unless the table setting is present. Terms: https://aws.amazon.com/service-terms/ and privacy: https://aws.amazon.com/privacy/.

Session data can hold personal data. Restrict the table to the application role and encrypt it at rest (DynamoDB does by default).

## Work on it

```sh
composer install
npm ci
npm run env:start      # WordPress at http://localhost:8926 (admin / password), needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check, as the directory runs it
```

## Release

Bump the version in the plugin header, `readme.txt` (`Stable tag`) and the `VERSION` constant, add a changelog line, tag `vX.Y.Z` and push. The release workflow attaches `shakewell-dynamodb-sessions.zip` to a GitHub release.

## Install with Composer

Install the zip from the GitHub release, pinned to a version with a checksum (`shasum -a 1 shakewell-dynamodb-sessions.zip`):

```json
"repositories": [
  {
    "type": "package",
    "package": {
      "name": "shakewellagency/shakewell-dynamodb-sessions",
      "version": "0.1.0",
      "type": "wordpress-plugin",
      "dist": {
        "url": "https://github.com/shakewellagency/shakewell-dynamodb-sessions/releases/download/v0.1.0/shakewell-dynamodb-sessions.zip",
        "type": "zip",
        "shasum": "<sha1 of the zip>"
      },
      "require": { "php": ">=8.1", "composer/installers": "^2.3" }
    }
  }
],
"require": { "shakewellagency/shakewell-dynamodb-sessions": "0.1.0" }
```

## Made by Shakewell

Part of a family of free WordPress plugins from [Shakewell](https://shakewell.agency). Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

See them all: [Shakewell plugins on GitHub](https://github.com/orgs/shakewellagency/repositories?q=shakewell-).
