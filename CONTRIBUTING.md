# Contributing

Thanks for helping. This file lists the rules a pull request must follow.

## Run the tests

The tests are plain PHP scripts. Each one exits non-zero on a failure. Run them in the
official PHP image, so you do not need PHP on your machine:

```sh
for f in tests/*vectors.php; do
  docker run --rm -v "$PWD":/sdk -w /sdk php:7.4-cli php "$f" || exit 1
done
```

With PHP 7.4 or later installed, `composer test` runs the same scripts. CI runs every script
on PHP 7.4 and PHP 8.3. CI also runs `php -l` over `src/` and `tests/` on both versions, and
`composer validate --strict`.

## Runtime floor

The floor is PHP 7.4 (the `php` line in `composer.json`). Do not use syntax or a function
that PHP 7.4 does not have: no union types, no `match`, no named arguments, no constructor
promotion, no `str_contains`. The library needs only `ext-curl`, `ext-json` and
`ext-mbstring`. It has no Composer dependencies. Keep it that way.

## Test fixtures

- `tests/merchant-api-contract.json` is the shared response contract. Every Dominaite SDK
  carries the same bytes. Do not edit it in a pull request here. It changes only after the
  API changes.
- `tests/merchant-api-wire-contract.json` is generated from the live production contract by
  `.github/workflows/contract-drift.yml`. Do not edit it by hand. When the drift check fails,
  the API moved: update the SDK first, then regenerate the fixture with the jq projection in
  that workflow.
- When a fixture test fails, fix the SDK, not the fixture.

## Tests with every change

Every change needs a test. Test what a caller gets back, not how the code works inside. A
bug fix needs a test that fails on the old code. A new test script ends in `_vectors.php`
and goes in the suite lists in `composer.json` and `.github/workflows/tests.yml`.

## Versions

The SDK is 0.x on purpose. A minor release (0.6 to 0.7) can contain a breaking change. A
breaking change goes in `CHANGELOG.md` under the next unreleased minor version. Do not
change `DominaiteClient::VERSION` in a pull request.

## Releases

The maintainer tags releases. A pull request never creates a tag or a GitHub release.

## Security

Do not report a security problem in a public issue or pull request. See
[SECURITY.md](SECURITY.md).
