#!/usr/bin/env bash
#
# PHPStan, fetched by checksum and run at the level in phpstan.neon.dist.
#
# A wrapper rather than a memory of the command, because the version, the hash and
# the level each live in exactly one place: this script fetches, phpstan.neon.dist
# configures, and continuous integration runs `composer analyse`.

set -euo pipefail

cd "$(dirname "$0")/.."

php tools/install-phpstan.php
php tools/phpstan.phar analyse --no-progress --autoload-file=vendor/autoload.php
