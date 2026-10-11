<?php
/**
 * Copyright © MageEnv. All rights reserved.
 * Licensed under the Open Software License 3.0 (OSL-3.0). See LICENSE.txt.
 */
declare(strict_types=1);

/*
 * Standalone bootstrap for running this module's unit tests in isolation:
 *
 *   vendor/bin/phpunit -c app/code/MageEnv/DynamicCartProgress/Test/Unit/phpunit.xml.dist
 *
 * It loads the Magento composer autoloader, which in turn executes every
 * app/code registration.php (via app/etc/NonComposerComponentRegistration.php),
 * so the module is registered with the ComponentRegistrar during the test run.
 *
 * When the tests are executed through Magento's own suite (dev/tests/unit),
 * that suite's bootstrap is used instead and this file is ignored.
 */

$autoloadFile = dirname(__DIR__, 6) . '/vendor/autoload.php';

if (!is_file($autoloadFile)) {
    throw new \RuntimeException(
        'Magento composer autoloader not found at ' . $autoloadFile
        . '. Run these tests from within a Magento 2 installation.'
    );
}

require $autoloadFile;
