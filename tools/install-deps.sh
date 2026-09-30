#!/bin/sh
# Installs dependencies inside the test container.
#
# turnkey/authclient is a private package pulled over SSH (vcs repository in
# composer.json). The container has no SSH key, so for local test runs the
# Makefile mounts a checkout of php-authclient at /authclient and this script
# builds composer.local.json that resolves it from there as a path repository.
set -eu

if [ -f /authclient/composer.json ]; then
    php -r '
        $c = json_decode(file_get_contents("composer.json"), true, 512, JSON_THROW_ON_ERROR);
        $c["repositories"] = [[
            "type" => "path",
            "url" => "/authclient",
            "options" => ["symlink" => false, "versions" => ["turnkey/authclient" => "0.1.0"]],
        ]];
        file_put_contents("composer.local.json", json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    '
    COMPOSER=composer.local.json composer update --no-interaction --no-progress -q
else
    composer install --no-interaction --no-progress -q
fi
