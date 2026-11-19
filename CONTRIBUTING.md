# Contributing

Use PHP 8.4 or newer with the `pcov`, `pcntl` and `posix` extensions, Composer 2, and a system with
pseudo-terminals (Linux or macOS) for the process driver.

```sh
make install
make fmt       # format with mago
make verify    # every gate CI runs
```

`make verify` runs Composer validation, mago formatting, linting and static analysis (every finding
fatal), PHPUnit, Behat on both drivers, mutation testing and a dependency audit. They are available
separately as `make validate`, `make fmt-check`, `make lint`, `make analyze`, `make test`,
`make behat`, `make infection` and `make audit`.

## Rules of the house

- The code follows the Symfony coding standard, as spelled out in `mago.toml`; `make fmt` applies it.
- Mutation testing requires 100% MSI: every mutant Infection makes in `src/` must be killed by a
  PHPUnit test. A surviving mutant is behavior no test pins down; kill it with a test, or remove the
  code if it does nothing.
- Every step definition must be used by a scenario in `features/`; a test fails otherwise. Scenarios
  run on both drivers unless tagged `@in_process` or `@process`.
- Steps that check something wait up to the timeout and fail with a message that ends with the
  screen. Write messages that say what was expected and what was found.
- Record user-visible changes under `[Unreleased]` in `CHANGELOG.md`, in the Keep a Changelog
  sections (Added, Changed, Deprecated, Removed, Fixed, Security).
- Use generic examples and synthetic fixtures. Do not commit credentials, personal paths, private
  service URLs or machine-specific output.

## Releases

Maintainers release from GitHub: Actions → Release → Run workflow, with an exact version or
`major`, `minor` or `patch`. The workflow uses [janalis/bumpversion](https://github.com/janalis/bumpversion)
to move `[Unreleased]` to the new version in `CHANGELOG.md` and `VERSION`, runs `make verify`,
commits and tags `vX.Y.Z`, publishes the GitHub release with the changelog section as its notes,
and has Packagist index it. It runs as a dry run unless that box is unticked; `make release-preview` shows locally what a minor release would change.

See [SECURITY.md](SECURITY.md) to report a vulnerability.
