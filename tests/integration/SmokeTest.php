<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use Craft;
use justinholtweb\spectacles\Plugin;

/**
 * Verifies the Craft test harness itself boots and the plugin is installed.
 */
class SmokeTest extends Unit
{
    public function testCraftIsBootstrapped(): void
    {
        $this->assertNotNull(Craft::$app);
    }

    public function testPluginIsInstalled(): void
    {
        $this->assertTrue(Craft::$app->plugins->isPluginInstalled('spectacles'));
        $this->assertInstanceOf(Plugin::class, Plugin::getInstance());
    }

    public function testInstallMigrationCreatedTheMetadataTable(): void
    {
        $this->assertTrue(Craft::$app->db->tableExists('{{%spectacles_imagemetadata}}'));
    }
}
