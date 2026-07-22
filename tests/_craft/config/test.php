<?php

/**
 * Application config used by the Codeception Yii2 module. Craft assembles the
 * whole thing (components, db, aliases) from the CRAFT_* constants defined in
 * tests/_bootstrap.php.
 */

use craft\test\TestSetup;

return TestSetup::createTestCraftObjectConfig();
