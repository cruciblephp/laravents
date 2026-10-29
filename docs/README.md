# Laravents Manual

The reference for Laravents. The [README](../README.md) is the thirty-second example; these pages are the rest, in reading order. Every code sample is an excerpt of a file the test suite runs, named under the sample, and a "Proven by" line at the end of a section names the tests that hold its rules.

The samples are excerpts of the example application in [`workbench/`](../workbench/README.md), which is not part of the package.

1. [Installation](installation.md): requirements, `composer require`, the config, the migration, the modules autoload line, the first module, the registry cache, `about`.
2. [Modules](modules.md): a module is a namespace, `Api` is public, a shared module, how routing keys and queues are derived, the explicit key, the configuration keys, turning a module off, a module's manifest.
3. [Commands and queries](commands-and-queries.md): `Sendable` and `Askable`, the facade by object or key, the pending builder, results, hydration, "Did you mean", `rules()`.
4. [Events](events.md): `Publishable`, matching by type and key, sync, `#[Deferred]` and `#[Asynchronous]` handlers, `#[Publishes]` on models, Laravel's events in, known limits, when not to go asynchronous.
5. [Interceptors](interceptors.md): `#[Authorize]`, `#[Transactional]`, writing an interceptor, pointcuts, precedence, what an interceptor may inject.
6. [Metadata and the actor](metadata-and-actor.md): the five keys, nested messages, `correlation_id`, reserved headers, the actor rules, `Laravents::as()`, plain Laravel jobs, `#[Header]`.
7. [The outbox](outbox.md): the row, its connection, the job, at-least-once delivery, retries and failures, republishing, worker-less hosts, the payload format, edge cases.
8. [The HTTP gateway](http-gateway.md): `#[Expose]`, the routes, validation, responses, error bodies, CSRF, caching.
9. [Testing](testing.md): `Laravents::fake()` and its assertions, Laravel's fakes next to it, a worker in a test, after-commit callbacks under `RefreshDatabase`, module tests on Testbench.
10. [Console](console.md): every command with its options and output, the generators and stubs, `about`, CI.
11. [Verification and docs](verification-and-docs.md): what `laravents:verify` checks, the scanner and its blind spots, the second check, `laravents:show`, `laravents:docs`.
12. [Reference](reference.md): every config key, attribute, trait, contract, facade method and exception.

The example application's generated module pages, written by `php artisan laravents:docs`, are in [laravents/](laravents/README.md).
