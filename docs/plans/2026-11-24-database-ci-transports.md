# Database CI socket and TCP coverage

Use a Unix socket for the nightly MySQL Innovation integration suite. Laravel's change uses socket paths for its database jobs and adds no tests. Hypervel runs PHP in a separate container, so the database service and PHP job must mount the same named volume. The Swoole nightly image contains the fix for cold `caching_sha2_password` authentication on Unix sockets. [Laravel PR](https://github.com/laravel/framework/pull/62323) · [Swoole fix](https://github.com/swoole/swoole-src/pull/6413)

## Workflow changes

- In `databases-nightly.yml`, mount `database-socket:/var/run/mysqld` in both Innovation containers. Set `DB_SOCKET: /var/run/mysqld/mysqld.sock` on the integration step and keep `DB_HOST: mysql`. The cold-authentication test uses the host to create a fresh account over TCP before connecting over the socket.
- Add a separate step with `DB_CONNECTION: mysql`, the service's TCP host, port and credentials, and no socket setting. Run the existing native dump test:

  ```sh
  vendor/bin/phpunit --no-progress tests/Integration/SchemaParser/Database/MySql/SchemaParserTest.php
  ```

  The test creates tables, a view and a procedure, dumps them with the native client, and checks the parsed schema against the live database. On MySQL 26 and later it selects Oracle's client. Running this one class over TCP preserves that client path while the main suite exercises its socket path.
- Keep the regular MySQL 8.4 and PostgreSQL 18 suites on TCP for both PHP minors. Other jobs already exercise sockets on both. Explain the PostgreSQL choice beside the job because local socket connections trust the user and bypass password authentication. The dedicated MySQL 8.4 TLS schema check keeps its own TCP settings.

## Verification and completion

1. Parse both workflows. Check the shared mount, the scope of the socket environment variable, and that the regular TCP jobs still use their service hosts.
2. Run `composer lint:fix`, then `composer analyse`.
3. Start a disposable `mysql:innovation` service with the workflow's named socket mount and run the MySQL suite in the PHP 8.4 Swoole nightly image. Check the server and client versions and confirm the cold-authentication and native dump tests execute over the socket. Run the dedicated TCP dump step and verify its assertions run.
4. Review the final diff and obtain peer code-review signoff. Remove the completed database entry from `docs/todo.md` and commit the change. Clean up disposable test resources.
