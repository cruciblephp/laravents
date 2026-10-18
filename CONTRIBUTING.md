# Contributing

Thank you for considering a contribution. This page says how a change is judged and how to check one before sending it.

## How a change is judged

In this order; an earlier rule wins over a later one.

1. **Laravel's shape first.** Where Laravel already has a shape for something (`Event::fake()`, the `make:*` generators, `route:list`, `model:show`, `php artisan about`, the exception handler, the container's contextual attributes), Laravents takes that shape and invents nothing of its own. A Laravel developer should be able to guess the API before reading it, and Laravel's own documentation keeps working for them.
2. **Standards at the boundary, never on the fields.** A standard or a protocol decides how Laravents meets the outside world: HTTP statuses and error bodies through the application's exception handler, the format of the `timestamp` header, how the outbox stores a payload so every database accepts it. It never decides what a command, query or event contains. A message's fields are the module's own, and no standard adds an envelope or a field to them.
3. **Tests prove every claim.** A rule in the manual, the README, a docblock or the CHANGELOG has a test that holds it, named in a "Proven by" line. A new mechanism comes with the test that fails without it ("proven necessary": break the mechanism, watch the test fail, restore it). A claim nothing can test is not made.
4. **One phase per commit.** A change is one commit named for what it delivers ("Phase N: name", "Phase N.M" for a follow-up), with `composer check` green. A reviewer reads one thing at a time, and `git bisect` lands on one thing.

## The one command

```bash
composer check
```

runs Composer's strict PSR-4 check (a class whose path differs from its namespace, even only in case, fails on every platform), Pint (`--test`), Rector (`--dry-run`), PHPStan at level max and the test suite, and fails on the first that fails. CI runs it on PHP 8.3, 8.4 and 8.5, then fails if the run changed any tracked file (`git diff --exit-code`), so a check that regenerates something and reports green is caught.

Do not read its result through a pipe: `composer check | tail` exits with `tail`'s status, which is 0, whatever the check did. Run it on its own, or with `set -o pipefail`, and look at the exit code.

## Before you send a change

- Generated documentation is current: `vendor/bin/testbench laravents:docs --path=$PWD/docs/laravents --realpath`, or the suite's `--check` test fails.
- The manual and the README say what the change does, with the sample taken from a file the suite runs.
- The CHANGELOG's unreleased entry has a line for a user-visible change: one line, starting with `Added`, `Fixed`, `Changed`, `Improved`, `Removed`, `Deprecated`, `Updated` or `Security`, with no trailing period.
- Coverage and mutation testing need a coverage driver: `pecl install pcov`, or xdebug loaded on demand. `vendor/bin/crucible --coverage`, then `vendor/bin/crucible mutate`, report the mutation score. It is 100%: every mutant is caught, so a survivor is a missing test, or code that does nothing and can go.

## Security

Do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
