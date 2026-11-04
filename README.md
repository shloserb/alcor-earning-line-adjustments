# Earning Line Adjustments

PHP 8.4 domain model for system-calculated earning lines, manual adjustments and their audit history. Uses DDD, CQRS and Event Sourcing without a framework.

## Run

Requires Docker and Docker Compose. From the project directory:

```sh
docker compose build app
docker compose run --rm app composer install
docker compose run --rm app composer check
docker compose run --rm app composer demo
```

`composer check` validates Composer configuration, runs PHPStan and executes PHPUnit tests.

`composer demo` reproduces the eight-step scenario from the assignment: a frozen system value of **$1,050.00**, adjustments totalling **$54.45**, and a final value of **$1,104.45**. The attempted recalculation to **$1,075.00** is recorded but does not change the value.

## Business rules

- A system calculation creates the earning line and can update its value until the first manual adjustment.
- The first adjustment permanently freezes the last system value, even if later adjustments cancel each other out.
- Adjustments must have a nonzero signed amount and a comment. Saved adjustments cannot be edited or deleted through the domain API.
- Mistakes are corrected by adding a compensating adjustment.
- The current value is the system value plus all adjustments. The history query returns that value, all adjustments, the first lock time and ignored recalculations.

## Design

- [Domain](src/Domain) contains the `EarningLine` aggregate, value objects and events. `Money` stores integer cents and checks for overflow.
- [Application](src/Application) contains command handlers, the repository and the history query.
- [Infrastructure](src/Infrastructure) provides an in-memory event store and a system clock.
- [bin/demo.php](bin/demo.php) wires the objects together and runs the example.

Event Sourcing makes the audit history the source of truth and allows an earning line to be rebuilt from its events. CQRS separates command handling from the history query, which reads the same event stream. Writes check the expected stream version and reject stale updates with `ConcurrencyConflict`.

## Assumptions and limits

- Amounts use USD with at most two decimal places. Negative system and final values are allowed. Money calculations do not use floating-point arithmetic.
- Comments must be valid UTF-8. Empty, whitespace-only and format-only comments are rejected.
- Callers supply earning-line and actor IDs. Authentication and payroll-period rules are outside this model.
- Data lives in memory and is lost when the process exits. Events are stored as PHP objects; the adapter assumes trusted callers and does not provide tamper-resistant storage.
- Repeating a command can create another event. Command deduplication is not implemented.

## Tests

Tests cover the assignment scenario, permanent locking, compensating adjustments, input validation, money overflow, event replay and version conflicts. The clock is injected so timestamps are deterministic in tests.
