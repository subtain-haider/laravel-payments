# Contributing to Laravel Payments

Thank you for considering contributing! This document explains the process for contributing to this project.

## How Can I Contribute?

### Reporting Bugs

Before creating a bug report, please check existing issues to avoid duplicates. When filing a bug, include:

- **PHP and Laravel version**
- **Package version** (`composer show subtain/laravel-payments`)
- **Steps to reproduce** the behavior
- **Expected behavior** vs what actually happened
- **Code snippets** or error logs (redact any API keys)

Use the [Bug Report](https://github.com/subtain-haider/laravel-payments/issues/new?template=bug_report.md) template.

### Suggesting Features

Feature requests are welcome. Please use the [Feature Request](https://github.com/subtain-haider/laravel-payments/issues/new?template=feature_request.md) template and describe:

- The problem you're trying to solve
- Your proposed solution
- Any alternatives you've considered

### Submitting Pull Requests

1. Fork the repo and create your branch from `main`
2. Install dependencies: `composer install`
3. Make your changes
4. Add or update tests for your changes
5. Run the test suite: `composer test`
6. Ensure code style passes: `composer format`
7. Commit with a clear message (e.g. `fix: webhook signature verification for empty payloads`)
8. Open a Pull Request against `main`

## Development Setup

```bash
git clone git@github.com:subtain-haider/laravel-payments.git
cd laravel-payments
composer install
```

### Running Tests

```bash
composer test
```

### Code Style

This project follows **PSR-12**. Format your code before committing:

```bash
composer format
```

## Coding Guidelines

- Follow existing code patterns and naming conventions
- Add PHPDoc types to all public methods
- Every new feature must include tests
- Every bug fix must include a regression test
- Keep PRs focused — one feature or fix per PR
- Do not introduce breaking changes without discussion in an issue first

## Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/):

- `feat: add subscription checkout support`
- `fix: webhook signature mismatch on re-serialized JSON`
- `docs: add embedded checkout example`
- `refactor: extract api_metadata parsing`

## Questions?

Open an issue or email **mail@syedsubtain.com**.
