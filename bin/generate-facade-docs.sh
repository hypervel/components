#!/usr/bin/env bash

cp composer.json composer.json.bak
cp composer.lock composer.lock.bak

composer config repositories.facade-documenter vcs git@github.com:hypervel/facade-documenter.git
composer require --dev hypervel/facade-documenter:dev-main
find src/support/src/Facades -type f -name '*.php' -printf '%f\n' | sort | grep -v Facade | sed -E 's/(.+)\.php/Hypervel\\\\Support\\\\Facades\\\\\1/' | xargs php -f vendor/bin/facade.php

mv composer.json.bak composer.json
mv composer.lock.bak composer.lock
composer install
