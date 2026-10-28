#!/usr/bin/env bash
#
# PHPStan, fetched by checksum and run at the level in phpstan.neon.dist.
#
# A wrapper rather than a memory of the command, because the version, the hash and
# the level each live in exactly one place: this script fetches, phpstan.neon.dist
# configures, and continuous integration runs `composer analyse`.
#
# The memory limit is the analyser's own rather than the one in the machine's
# `php.ini`, because the two are not the same: a stock `php.ini` gives PHP 128M,
# which is enough for one branch of this code and not enough for two of them
# stacked, and the run then fails on a cold cache and passes on a warm one. The
# limit here is the same wherever the script runs.

set -euo pipefail

cd "$(dirname "$0")/.."

php tools/install-phpstan.php
php tools/phpstan.phar analyse --no-progress --memory-limit=1G --autoload-file=vendor/autoload.php
