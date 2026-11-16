# Lupa

An MCP server that lets an AI assistant (Claude Code, Codex and other MCP clients) look inside a running PHP application built on Nette 2.4 or 3: the DI container, presenters, routes, forms, permissions, Latte templates, Tracy reports, the database through Dibi or nette/database, Ruckusing migrations and the file cache.

It is made for legacy applications too (PHP 7.4, `nette/di` 2.4.16+, Tracy 2.4+, Latte 2.4+), and for development environments only, with safeguards against leaking data and against writes.

*Lupa* is Czech for a magnifying glass.

Versions 0.x are early: the names of tools and the keys of the configuration may still change before 1.0.

## How it works

- `lupa serve` speaks MCP over stdin/stdout and never loads the application itself.
- Every tool call runs in a fresh PHP process, so the code is always current and a fatal error, exhausted memory or a time-out ends only that call.
- Before the application's DI container is initialized, an environment guard reads from the compiled container code where every Dibi, nette/database, PDO and Kdyby Redis connection points, without connecting. A host that is not allowed, a denied database name or a target it cannot determine stops the call. Other clients (Doctrine DBAL, Nextras, Predis, HTTP clients) and connections the application creates outside the container are not guarded.
- Database tools run in read-only transactions that are rolled back, with limits on time, rows, columns and cell length. Both `db_query` and `db_explain` take a direct `SELECT` over base tables, with aliases, joins, `*` and common aggregates, and values for its `?` placeholders. CTEs, subqueries, views and generated columns are refused. Use `db_table` for schema details and `db_explain` for query plans.
- E-mails, phone numbers, birth numbers, bank accounts, card numbers, values under secret-looking keys, credentials in URLs, bearer tokens, JWTs and password hashes are masked in results, and so are the columns the configuration names. `db_query` can return a masked column under its original name, but cannot filter, join, group, sort or compute with it. `db_explain` accepts only public columns. Errors of the database driver are replaced by a generic message. Database comments are masked too. `db_table` shows a column default only when it is a number, an empty value, a date or time, a common SQL expression such as `CURRENT_TIMESTAMP` or a value of an enum; any other text default is masked. A database value or comment beginning with `{` or `[` is treated as JSON; if it begins like a document (`{"`, `[{`, `["` or `[[`) and cannot be decoded, including excessive nesting, the whole value is hidden, while text such as `[a note]` is masked as text.
- Results that carry values from the database, logs, the cache or a rendered page say in a `_notice` field that these are data, not instructions.
- A Configurator returned by the bootstrap is switched to the debug mode, as on a development machine. In the production mode, which the Configurator detects on the command line, a change of the configuration would never rebuild the container.
- When the configuration of the application stops compiling, the tools answer from the container of the last successful build and every result carries a `_warning` saying so. This needs a bootstrap that returns the Configurator.
- The Tracy tools read the directory in the `logDir` parameter, or else the one Tracy was enabled with, e.g. by `enableTracy()`.
- `tracy_errors`, `tracy_report`, `latte_map_line`, `cache_namespaces` and `cache_get` read only files. They answer also when the application does not start at all, from the directories of its last start under Lupa, or from `log` and `temp` under the project root, with a `_warning` saying so. They never initialize the container. Without a start the environment guard has nothing to check; these tools connect nowhere and read only files of the project.
- Every call is written to an audit log. A log over 10 MB moves to a file with the suffix `.1`, replacing the previous one. `LUPA_DISABLED=1` or `enabled: false` turns everything off.

## Tools

| Area | Tools |
| --- | --- |
| Overview | `app_info` |
| DI container | `di_services`, `di_service`, `di_dependents`, `di_parameters`, `di_extensions` |
| Presenters and routing | `presenters`, `presenter`, `routes`, `route_match`, `link` |
| Forms and permissions | `form`, `acl_roles`, `acl_check`, `acl_matrix` |
| Tracy and Latte | `tracy_errors`, `tracy_report`, `latte_map_line`, `latte_check` |
| Database | `db_tables`, `db_table`, `db_relations`, `db_query`, `db_explain`, `db_env`, `db_usage` |
| Migrations, cache, console | `migrations_status`, `migrations_conflicts`, `cache_namespaces`, `cache_get`, `console_commands` |
| Active, off by default | `render`, `cache_invalidate` |

For example:

- `tracy_report` reads a BlueScreen, follows the chain of exceptions and maps frames of compiled templates to the line of the `.latte` file. On request it adds the markdown report of Tracy 2.12+, with the arguments of the calls.
- `di_dependents` lists the services and presenters that receive a service, and the files that refer to its class.
- `migrations_conflicts` finds Ruckusing migrations sharing a version or a class name, and files declaring another class than their name says.
- `db_explain` warns about full table scans and about indexes the optimizer ignores, which after a bulk insert usually means stale statistics.

Active tools run code of the application or delete data, so each has to be named in `inspector.enableActive`.

`render` runs a presenter for a GET request, optionally as a user with given roles. It needs a bootstrap that returns the Configurator. Before the container is initialized:

- mailers are replaced by a recorder;
- guarded database sessions become read-only, and on MySQL, MariaDB and PostgreSQL the server limits their statements to `inspector.timeout`, because it would otherwise go on running a query after the worker is stopped;
- a Dibi or Nette Database connection that cannot be opened yet is made read-only when the application connects it, while a plain PDO connection that cannot be made read-only stops the tool.

Files, sessions and external services are outside these safeguards, so keep the bootstrap and the connection factories free of side effects.

## Installation

```bash
composer require --dev jancinert/lupa
vendor/bin/lupa init        # creates lupa.neon, lupa-bootstrap.php and .mcp.json
vendor/bin/lupa call app_info
```

`init` writes a bootstrap that works with `App\Bootstrap::boot()` and with the `App\Bootstrap` of a current nette/web-project; for any other application it says what the file has to return. Then check that `lupa-bootstrap.php` returns the application's `Configurator` without side effects (no session start, no output, no database queries, no error reporting to external services), add the database host to `guard.allowedHosts` and restart the AI client. When the application runs in Docker, `init --docker <container>` writes a `docker exec` entry into `.mcp.json`.

The server is registered in `.mcp.json`, which Claude Code reads, and in `.cursor/mcp.json` and `.vscode/mcp.json` where those directories exist. Another client gets the same command with the full path, e.g. `codex mcp add lupa -- php /path/to/app/vendor/bin/lupa serve`.

Lupa can also run from its own checkout next to an application that does not have it installed: `php bin/lupa call app_info --project <application> --config <lupa.neon>`.

## Configuration

`lupa.neon` configures Lupa's own DI container. Every toolkit is an extension with its section:

```neon
inspector:
	bootstrap: lupa-bootstrap.php            # returns the Configurator of the application
	timeout: 30
	disabledTools: []
	enableActive: []                         # e.g. [render, cache_invalidate]
	applicationConfig: [lupa.local.neon]     # added to the Configurator, e.g. a read-only database account

guard:
	allowedHosts: [localhost, 127.0.0.1, '::1', mysql, redis]
	deniedDatabases: ['*_test', '*_prod']

masking:
	keys: []
	columns:
		users: [firstname, lastname, street]
	detectors: [email, phone, birthNumber, iban, card]

database:
	connection: database                     # service name when there are more connections
	rowLimit: 100

migrations:
	directory: app/migrations
	table: schema_migrations

services:
	- App\Inspector\ProjectToolkit             # tools of the project, see below
	- App\Inspector\ProjectPermissionProvider  # Lupa\Nette\PermissionProvider, when the ACL is built outside the container
```

## Limits of masking

Masking recognizes secrets by the names of keys and columns and personal data by their shape, so it cannot recognize everything:

- a secret under an ordinary name, such as `stripe: sk_live_…` or a column `note` holding private remarks;
- a label or a sentence it does not know, such as "Current password" in a page, a field named in several words or `Passwort ist …`;
- data encoded other than in UTF-8, e.g. base64 of Latin-1 or UTF-16 text, gzip or hex.

Name such keys in `masking.keys` and such columns in `masking.columns`, and let the database account of `inspector.applicationConfig` read only what the tools need. Masking is a safety net for a development environment, not a way to inspect production data.

## Applications without a framework

The database tools, the Tracy tools, the migrations and the tools of the project need no framework. For an application that has none, `vendor/bin/lupa init --framework none` sets `framework: none` in `lupa.neon` and writes a bootstrap that returns an array instead of a Configurator:

```php
return [
	'parameters' => [
		'appDir' => __DIR__ . '/src',
		'logDir' => __DIR__ . '/log',
	],
	'databases' => [
		'main' => ['dsn' => 'mysql:host=127.0.0.1;dbname=app', 'user' => 'app', 'password' => getenv('DB_PASSWORD') ?: ''],
		'legacy' => ['driver' => 'mysqli', 'host' => '127.0.0.1', 'database' => 'old', 'username' => 'app'],   // Dibi
	],
];
```

Only `appDir`, `logDir` and `tempDir` are read from the parameters. A connection is described by what creates it, never passed as an object, so that the environment guard reads where it points before anything connects. The tools for the DI container, presenters, routing, forms, permissions, Latte, the cache and rendering are not offered.

## HTTP on shared hosting

`Lupa\Http\Endpoint` serves stateless Streamable HTTP for MCP versions 2025-03-26, 2025-06-18 and 2025-11-25. Each POST carries one JSON-RPC request or notification. Results use JSON; GET streams, sessions and batch requests are not supported. The stdio transport remains available for environments with PHP CLI and `proc_open`.

Keep the package, configuration and audit outside the public document root. A public PHP entry point can load a private installation:

```php
require dirname(__DIR__) . '/storage/lupa/vendor/autoload.php';
$root = dirname(__DIR__);
$settings = require $root . '/storage/lupa/http-auth.php';
$project = new Lupa\Project($root, $root . '/vendor/autoload.php', $root . '/storage/lupa/lupa.neon');
(new Lupa\Http\Endpoint($project, $settings['tokenHash'], ['https://example.com']))->serve();
```

`tokenHash` is the lowercase SHA-256 hash of a random 32-byte bearer token. Only the MCP client needs the original token. Send it in the `Authorization: Bearer ...` header over HTTPS; clients must support an explicit authorization header. This binding does not implement OAuth discovery. Forwarded headers do not establish HTTPS. When an `Origin` header is present it must match one of the configured origins exactly. Authentication and HTTP validation happen before the project autoloader or configuration is loaded.

Apache hides the `Authorization` header from PHP, both from mod_php and from PHP-FPM. Enable it only for the endpoint in the existing `.htaccess`:

```apache
<Files "lupa-mcp.php">
	CGIPassAuth On
</Files>
```

Verify that your provider permits this directive before deploying it. The endpoint accepts `HTTP_AUTHORIZATION` and the `REDIRECT_HTTP_AUTHORIZATION` CGI fallback; it never accepts a token in the URL.

The HTTP mode requires `framework: none` and offers only `app_info`, `db_tables`, `db_table`, `db_relations` and `db_env` from the built-in toolkits. Connections must use PDO and point to existing SQLite files inside the project. They are reopened with SQLite's read-only flag. Other drivers, arbitrary SQL, custom toolkits, rendering and active tools are refused. The HTTP mode does not run the application's normal bootstrap or offer arbitrary project code execution; its configured bootstrap must only return the plain application description.

Without a killable subprocess, PHP's execution limit cannot guarantee the same wall-clock deadline for arbitrary database queries. The HTTP mode therefore does not offer `db_query` or `db_explain`. It accepts at most 64 KiB of JSON, caps responses at 1 MiB, permits one execution at a time and accepts at most 60 JSON-RPC requests in any 60 seconds per audit file. The token is checked before the request body is read. Valid notifications are acknowledged without loading the project. Keep separate audit files for separately configured endpoints.

Audit files, lock files and cache directories must belong to the PHP user and be private on Unix. Files are created with `umask(0077)`. On Windows, provision an equivalent private DACL. Tool results and errors use the masking policy; application output, cookies and diagnostic headers are discarded through shutdown. `serve()` takes control of output buffering and refuses an inherited buffer it cannot remove. It commits safe headers before loading project code and normally sends the vetted body before application shutdown callbacks run. Execution failures (including a busy or rate-limited executor) are JSON-RPC errors. Their HTTP status is 200 where the server sends the committed headers at once, as mod_php and the built-in server do, and the status of the failure (such as 500 or 429) where it sends them only with the body, as PHP-FPM does. Notifications keep HTTP 202 with an empty body. Authentication and request-validation failures retain their HTTP error status. A fatal error or an early `exit()` before completion returns a fixed error and records an incomplete request without copying the PHP diagnostic into the HTTP response or audit. A failure in a later application shutdown callback does not change an already delivered result.

If application code creates a nonremovable output buffer, execution returns a fixed error and lets PHP drain that buffer through the endpoint's protective filter at shutdown. Cleanup never retries a buffer it cannot remove. An output handler that itself throws, exits or fails during final flushing can prevent PHP from delivering any body; clients must treat an empty or incomplete reply as a transport failure.

The integration client in `tests/http-client.mjs` uses `@modelcontextprotocol/sdk` 1.32.2. Install its pinned dependencies with `npm ci --prefix tests --ignore-scripts`, then run `node tests/http-client.mjs <private-configuration.json> <results.json>`. The configuration contains `url` and `token`. It performs real initialization, tool discovery, schema reads, masking checks and refusal checks. The fixtures in `tests/fixtures/http` are for isolated synthetic projects only; their local HTTP server simulates the HTTPS server variable and must not be deployed.

## Tools of the project

A class extending `Lupa\Tools\MethodToolkit` turns its public methods into tools. The method name gives the tool name (`findOrders` is `find_orders`), the doc comment the description, the parameters the arguments:

```php
final class ProjectToolkit extends Lupa\Tools\MethodToolkit
{
	/**
	 * Lists the newest orders of a customer.
	 * @param string $customer  e-mail of the customer
	 */
	public function findOrders(string $customer, Lupa\Tools\AppContext $context, int $limit = 10): array
	{
		// ...
	}
}
```

Parameters are `string`, `int`, `float`, `bool` or `array`; one with a default value is optional. A parameter typed `AppContext` receives the booted application. A method tagged `@active` has side effects and stays off until `enableActive` names it; one tagged `@untrusted` returns data that may hold user input, and its result tells the model not to follow instructions found there. A method that returns an object of a class instead of an array gives the client the schema of the result too, read from the public typed properties of the class. For full control over the schemas, implement `Lupa\Tools\Toolkit` instead.

A project toolkit answers for what it returns: ask for `Lupa\Masking` in the constructor and pass results through `maskData()`, and open database connections through `Lupa\Database\Connections` to keep them read-only.

The worker also masks every result and diagnostic before writing it to the client. Toolkits still need to supply context for values such as a password option's default or a form validation argument. Structured strings, encoded URL parameters and dictionary keys are masked too, and so are values written with percent-encoding, quoted-printable, base64, HTML entities or JSON escapes, or separated by Unicode spaces, values in `print_r()` and `var_dump()` output (nested arrays and objects as a whole), and fields like `["password", "x"]` or `{"name": "password", "value": "x"}`. Parser errors, invalid UTF-8, excessive nesting, text that keeps decoding after several rounds and text larger than 1 MiB hide the affected value. SQL string literals in PostgreSQL index expressions are hidden. HTML is parsed before masking and written back from the masked nodes, with a value next to the label of a secret (such as Password, Heslo, Token or API key) hidden: in the next cells, in the column under a header, in a `<dd>`, in the control of a `<label>` and after any element holding just the label; a page with data in the names of its tags, without `dom` or one that cannot be parsed is hidden as a whole. Configure extra keys and columns for secrets that cannot be recognized by their name or shape.

## Requirements

PHP 7.4 to 8.5, `nette/di` 2.4.16+ or 3.0.1+, `nette/neon` 2.4, 3 or 4, `nette/schema` 1. The toolkits use what the application has: nette/application, Latte 2.4+ or 3, Tracy 2.4+, Dibi 3.2+, nette/database, nette/forms, nette/security, nette/caching, Symfony Console. The `json` and `tokenizer` extensions are required. Without `dom`, Tracy reports cannot be read and an HTML page with a secret is hidden as a whole instead of masked in place. The `mbstring` extension is optional, unless a library of the application needs it, as `nette/neon` 4 does; `pdo`, `mysqli`, `pgsql` and `sqlite3` are needed only by the connections of the application that use them, and `posix` adds the check of file owners on Unix. Some builds of PHP, such as the deb.sury.org packages of PHP 7.4 to 8.1, end the process at once when the memory runs out, without shutdown functions; such a call fails with an error instead of falling back to the tools that read only files, and the HTTP mode sends an empty response. The database tools run on MySQL 5.7.8+, MariaDB 10.1+, PostgreSQL (tested with 17) and SQLite; older MySQL and MariaDB cannot limit the time of a statement and are refused.

## Development

```bash
composer update
vendor/bin/tester tests -s -C
```

To test both transports with the official MCP client on a disposable SQLite project:

```bash
npm ci --prefix tests --ignore-scripts
npm run --prefix tests test:mcp
# Or select a PHP binary explicitly:
node tests/mcp-test.mjs /path/to/php
```

The HTTP fixture listens only on loopback, simulates HTTPS and disables `proc_open`. This checks protocol behavior, not a production server's TLS configuration. The test also checks that the SQLite file is unchanged. CI runs the client and PHP tests on PHP 7.4 and 8.5 with and without mbstring, and checks masking separately without DOM.

The tools run in worker processes, which the code coverage of Nette Tester does not see on its own. Setting `auto_prepend_file` to `tests/coverage-prepend.php` in `php.ini` adds them to the report; an option on the command line would not reach the workers:

```bash
vendor/bin/tester tests -s -C --coverage coverage.html --coverage-src src
```

## License

[BSD-3-Clause](LICENSE), © 2026 Jan Cinert.

Lupa follows the idea of [nette/mcp-inspector](https://github.com/nette/mcp-inspector), but it is a separate implementation without its code and is not affiliated with the Nette Foundation. Nette, Latte and Tracy are projects of the Nette Foundation.
