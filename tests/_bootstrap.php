<?php

/**
 * Codeception bootstrap for the Spectacles plugin.
 *
 * Craft's test framework expects the CRAFT_* path constants to be defined
 * before TestSetup::configureCraft() runs, and it reads database credentials
 * out of the environment (see craft\test\TestSetup::createDbConfig()), so the
 * .env file has to be loaded here rather than via Codeception's `params`.
 */

use craft\test\TestSetup;
use Dotenv\Dotenv;

ini_set('date.timezone', 'UTC');

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');

// createUnsafeImmutable so the values land in getenv() too — craft\helpers\App::env()
// falls back to getenv() and would not otherwise see them.
Dotenv::createUnsafeImmutable(__DIR__)->safeLoad();

TestSetup::configureCraft();
