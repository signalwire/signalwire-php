#!/usr/bin/env bash
# _env.sh — shared tool-environment bootstrap for signalwire-php's FMT + LINT +
# TEST scripts (and run-ci.sh). Sourced, never executed. Holds the composer/vendor
# bootstrap in ONE place so scripts/run-format.sh, scripts/run-lint.sh,
# scripts/run-tests.sh, and scripts/run-ci.sh all resolve the toolchain identically
# no matter the caller's CWD or shell setup (see porting-sdk/RUN_LINT_FORMAT_SPEC.md).
#
# Contract: after sourcing this file,
#   * $REPO_ROOT   — absolute path to the repo root (resolved from THIS file's own
#                    location, so it is correct from any CWD).
#   * vendor/bin is on $PATH and the composer dev-deps are installed (composer
#                    install on first miss), so php-cs-fixer / phpstan / phpunit /
#                    paratest all resolve as bare names.
#   * `sw_php_tool <bin> [args…]` — run a vendored bin (php-cs-fixer, phpstan,
#                    phpunit, paratest) from $REPO_ROOT, having ensured vendor/ is
#                    bootstrapped. Fails loud with an install hint if composer is
#                    unavailable and vendor/ is missing.
#
# The PHP tools: FMT = php-cs-fixer (`fix` = apply / `fix --dry-run` = read-only
# check), LINT = phpstan (level 9, zero findings), TEST = phpunit.

# Resolve the repo root from this script's OWN path (CWD-independent). This file
# lives at <repo>/scripts/_env.sh, so the repo root is its parent's parent.
_SW_ENV_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$_SW_ENV_DIR")"
export REPO_ROOT

# Ensure the composer vendor tree (dev deps) resolves. The canonical invocation is
# the vendored bin (vendor/bin/<tool>), pinned by composer.lock — never a stray
# global tool. If vendor/ isn't installed yet, run `composer install` once; if that
# still can't produce a working vendor/bin, fail loud with an install hint.
_sw_bootstrap_vendor() {
    # Already bootstrapped? vendor/bin present with the key tools.
    if [ -x "$REPO_ROOT/vendor/bin/phpstan" ] \
        && [ -x "$REPO_ROOT/vendor/bin/php-cs-fixer" ] \
        && [ -x "$REPO_ROOT/vendor/bin/phpunit" ]; then
        return 0
    fi
    if ! command -v composer >/dev/null 2>&1; then
        echo "FATAL: composer not found on PATH and vendor/ is not installed." >&2
        echo "       Install Composer (https://getcomposer.org/), then from" >&2
        echo "       $REPO_ROOT run: composer install" >&2
        return 1
    fi
    echo "==> vendor/ not installed; running 'composer install' ..." >&2
    if ! (cd "$REPO_ROOT" && composer install --no-interaction >&2); then
        echo "FATAL: 'composer install' failed — cannot bootstrap the PHP tools." >&2
        echo "       From $REPO_ROOT run: composer install" >&2
        return 1
    fi
    if [ ! -x "$REPO_ROOT/vendor/bin/phpstan" ] \
        || [ ! -x "$REPO_ROOT/vendor/bin/php-cs-fixer" ] \
        || [ ! -x "$REPO_ROOT/vendor/bin/phpunit" ]; then
        echo "FATAL: the PHP tools are still unavailable after 'composer install'." >&2
        echo "       Ensure composer.json's require-dev declares php-cs-fixer," >&2
        echo "       phpstan, and phpunit, then run: composer install" >&2
        return 1
    fi
    return 0
}

# Put vendor/bin on PATH so the tools resolve as bare names for any caller that
# sources this file (bootstrapping on first source is deferred to sw_php_tool so a
# bare source never triggers a network install — but the PATH entry is free).
case ":$PATH:" in
    *":$REPO_ROOT/vendor/bin:"*) : ;;
    *) export PATH="$REPO_ROOT/vendor/bin:$PATH" ;;
esac

# sw_php_tool <bin> [args…] — run a vendored PHP tool from the repo root,
# bootstrapping vendor/ on first call. PHP_CS_FIXER_IGNORE_ENV=1 is harmless for
# the other tools and keeps php-cs-fixer from refusing on a newer PHP runtime than
# it formally supports (the rule set used here is version-stable).
sw_php_tool() {
    _sw_bootstrap_vendor || return 1
    local bin="$1"; shift
    (cd "$REPO_ROOT" && PHP_CS_FIXER_IGNORE_ENV=1 "vendor/bin/$bin" "$@")
}
