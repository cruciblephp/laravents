# Security Policy

## Supported versions

Security fixes are released for the latest minor version only.

| Version | Supported |
|---|---|
| 1.0.x (latest minor) | Yes |
| Anything older | No: upgrade to the latest minor |

## Reporting a vulnerability

Report a vulnerability privately, by email to **info@cruciblephp.com**. Do not open a public issue, pull request or discussion for it.

Please include the Laravents, Laravel and PHP versions, what an attacker can do, and the smallest reproduction you have (a test against the workbench is ideal).

## What to expect

- An acknowledgement within 3 working days.
- An assessment (accepted or not, and why) within 10 working days.
- For an accepted report, a fix released as a patch version, and a GitHub security advisory crediting the reporter unless they ask not to be named. The timeline depends on the severity and is agreed with the reporter; the details stay private until the fix is released.

## In scope

The parts of Laravents that decide who may do what, and what it reads back from storage:

- **The HTTP gateway**: `POST {prefix}/commands/{key}` and `GET|POST {prefix}/queries/{key}`, including reaching a handler that is not `#[Expose]`d, telling an unexposed key from an unknown one, validation being skipped, and an `X-Correlation-ID` header that reaches a message or the outbox without being validated.
- **`#[Authorize]` and the actor rules**: a message running as a user the guard does not hold, a header becoming an actor, an `ActorMismatchException` that should have been thrown and was not.
- **The actor rebuild in `ExecuteHandlerJob`**: an asynchronous handler running as a different user than its publication's actor, or a guard left holding the actor after the job.
- **`Laravents::as()`**: the guard or the actor keys not restored after the dispatch, also on an exception.
- **Outbox payload deserialization**: `EventPublication::message()` unserializes the stored payload; anything that lets data other than what Laravents wrote reach `unserialize()`, or that turns a corrupt row into code execution.

## Out of scope

- The example application in `workbench/`: its users, Gate rules and modules exist for the test suite and the documentation, not for production.
- The output of the generators (`laravents:module`, `laravents:command`, ...): generated code is the application's from the moment it is written, as with Laravel's `make:*` commands. A problem in a stub itself is in scope.
- Vulnerabilities in Laravel, PHP or a database driver: report those to their maintainers.
- An application's own Gate abilities, middleware and exception handler, which Laravents uses as the application configures them.
