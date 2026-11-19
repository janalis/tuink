# Developer entry points. `make verify` runs every gate CI runs.
.DEFAULT_GOAL := help

COMPOSER ?= composer
PHP ?= php
COVERAGE_PHP_FLAGS ?= -d pcov.enabled=1 -d pcov.directory=src
INFECTION_THREADS ?= max

.NOTPARALLEL:
.PHONY: help install tools validate fmt fmt-check lint analyze test behat infection audit verify release-preview clean

help: ## List developer commands
	@awk 'BEGIN { FS = ":.*## " } /^[a-z-]+:.*## / { printf "  %-12s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

install: ## Install dependencies
	$(COMPOSER) install --no-interaction --prefer-dist

tools: ## Install janalis/bumpversion apart from the project's dependencies
	$(COMPOSER) install --no-interaction --prefer-dist --working-dir=tools/bumpversion

validate: ## Validate composer.json
	$(COMPOSER) validate --strict --no-check-lock

fmt: ## Apply Mago formatting
	vendor/bin/mago format

fmt-check: ## Check Mago formatting without changes
	vendor/bin/mago format --check

lint: ## Mago lint, failing on any finding
	vendor/bin/mago lint --minimum-fail-level=note

analyze: ## Mago static analysis, failing on any finding
	vendor/bin/mago analyze --minimum-fail-level=note

test: ## Run the PHPUnit suite
	$(PHP) vendor/bin/phpunit

behat: ## Run the Behat suite on both drivers
	$(PHP) vendor/bin/behat --strict
	$(PHP) vendor/bin/behat --strict --profile=process

infection: ## Mutation testing: every mutant must die (infection.json5.dist)
	$(PHP) $(COVERAGE_PHP_FLAGS) vendor/bin/infection --threads=$(INFECTION_THREADS) --initial-tests-php-options="$(COVERAGE_PHP_FLAGS)"

audit: ## Check dependencies for security advisories
	$(COMPOSER) audit

verify: validate fmt-check lint analyze test behat infection audit ## Every gate CI runs

release-preview: tools ## Show what a minor release would change (needs a clean working tree)
	tools/bumpversion/vendor/bin/bumpversion release:prepare minor --tag-prefix=v --dry-run

clean: ## Remove generated reports and caches
	rm -rf .phpunit.cache var
