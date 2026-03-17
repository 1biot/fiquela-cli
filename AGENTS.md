# AGENTS.md — FiQueLa CLI

> Guidelines for AI coding agents working in this repository.

## Project Overview

PHP 8.2+ CLI tool for executing SQL-like queries on structured data files (CSV, JSON, XML, YAML, NEON, XLS/XLSX) and via the FiQueLa API. Uses Symfony Console, supports interactive REPL and non-interactive modes.

- Root namespace: `FQL\` (PSR-4 mapped to `src/`)
- Two main modules: `FQL\Cli` (CLI application) and `FQL\Client` (standalone API client library)

## Build / Lint / Test Commands

```bash
composer test                    # Run all checks (phpcs + phpstan + phpunit)
composer test:phpcs              # PHP_CodeSniffer (PSR-12)
composer test:phpstan            # PHPStan static analysis (level 8)
composer test:phpunit            # PHPUnit tests
composer test:phpunit:coverage   # PHPUnit with coverage summary
composer utils:phpcbf            # Auto-fix code style violations

# Single test file / method / directory
./vendor/bin/phpunit tests/Client/FiQueLaClientTest.php
./vendor/bin/phpunit --filter testLogin tests/Client/FiQueLaClientTest.php
./vendor/bin/phpunit tests/Cli/Config/
```

## CI Pipeline

GitHub Actions on push to `main` and all PRs. Matrix: PHP 8.2, 8.3, 8.4, 8.5.
Steps: Composer install -> PHP_CodeSniffer -> PHPStan -> PHPUnit with coverage.

## Code Style

**PSR-12** enforced by PHP_CodeSniffer (`phpcs.xml`). `PSR12.Files.FileHeader` excluded. Run `composer utils:phpcbf` to auto-fix before committing.

**PHPStan Level 8** — maximum strictness, covers `src/` and `bin/`.

- One class per file, strict PSR-4 directory-to-namespace mapping
- 4-space indentation, soft limit 120 chars line length
- Opening brace on same line for control structures, next line for classes/methods

### Imports

- One `use` per line, no grouped imports, alphabetical ordering
- Internal (`FQL\...`) first, then external (Symfony, etc.)
- Aliases only for name conflicts (e.g., `use FQL\Client\Dto\QueryResult as ApiQueryResult`)

### Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Classes | `PascalCase` | `QueryCommand`, `FiQueLaClient` |
| Methods/Properties/Params | `camelCase` | `executeAll`, `$baseUrl` |
| Constants | `UPPER_SNAKE_CASE` | `REQUIRED_PERMISSIONS` |
| Test methods | `testDescriptiveName` | `testHasTerminatingSemicolonSimple` |

### Type Annotations

- **Always** declare parameter types, return types, and property types (PHP 8.2+)
- PHPDoc only when PHP's type system is insufficient: `@param array<string, mixed>`, `@return string[]`, `@throws`
- Use `#[\SensitiveParameter]` on password/secret parameters

### Constructor Patterns

**DTOs** — constructor property promotion with `readonly`:
```php
public function __construct(
    public readonly string $query,
    public readonly string $file,
) {
}
```

**Service classes** — traditional constructor with assignment:
```php
public function __construct(?string $file = null, string $delimiter = ',')
{
    $this->file = $file;
    $this->delimiter = $delimiter;
}
```

### DTO Pattern

All DTOs: `public readonly` properties + `fromArray(array $data): self` static factory with safe defaults:
```php
/** @param array<string, mixed> $data */
public static function fromArray(array $data): self
{
    return new self(
        query: (string) ($data['query'] ?? ''),
    );
}
```

### Method Visibility

- **Public**: API surface and interface implementations only
- **Private**: Internal implementation
- **Protected**: Only Symfony Console overrides (`configure()`, `execute()`)
- **Static**: Only utility functions and `fromArray()` factories

### String Handling

- `sprintf()` for interpolation — not double-quote variable interpolation
- JSON flags: `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` for config, `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` for output

## Error Handling

Exception hierarchy:
```
RuntimeException
  └── ClientException (base)
        ├── AuthenticationException (401)
        ├── NotFoundException (404)
        ├── ValidationException (422, carries error details)
        └── ServerException (500+)
```

- Catch specific exception types; bare `\Exception` only for non-critical failures
- Use `match` expressions for HTTP status -> exception mapping
- Always check `json_decode` (verify `is_array()`), `file_get_contents`, `file_put_contents` return values

## Testing (PHPUnit 10)

**Namespace convention**: mirrors `src/` but without `FQL\` root prefix:
- `FQL\Client\FiQueLaClient` -> test: `Client\FiQueLaClientTest`

**File naming**: `{ClassName}Test.php` in corresponding `tests/` directory.

**Patterns**:
- Extend `PHPUnit\Framework\TestCase`
- `setUp()`/`tearDown()` for temp files (use `sys_get_temp_dir()` + `uniqid()`; clean up in `tearDown`)
- Group related tests with `// -------------------------------------------------------` dividers
- `$this->markTestSkipped()` when runtime features unavailable (e.g., readline)

**Mocking**: prefer custom mock implementations (`MockTransport`, `FakePagedExecutor`) over PHPUnit mock builder. Use `$this->createMock()` only for complex collaborators.

## Architecture

- **Strategy pattern**: `QueryExecutorInterface` (local vs. API); `HttpTransport` (real vs. mock)
- **DTO pattern**: Immutable value objects with `fromArray()` factories
- **Dependency injection**: `HttpTransport` injected into `FiQueLaClient` (defaults to `CurlTransport`)
- **Separation of concerns**: `Client/` is independent of `Cli/` and reusable standalone

## Key Dependencies

| Package | Purpose |
|---|---|
| `symfony/console ^8.0` | CLI framework |
| `1biot/fiquela ^2.5` | Core query engine |
| `ext-readline` | Interactive REPL |
| `ext-curl` | HTTP transport |
| `phpunit/phpunit ^10` | Testing |
| `phpstan/phpstan 1.12.13` | Static analysis |
| `squizlabs/php_codesniffer ^3.11` | Code style (PSR-12) |
