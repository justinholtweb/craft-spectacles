<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use Craft;
use justinholtweb\spectacles\controllers\SearchController;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use ReflectionMethod;
use yii\web\TooManyRequestsHttpException;

/**
 * The anonymous search endpoints fan out to paid vision/embedding APIs, so the
 * throttle in front of them is the thing standing between a visitor and an
 * unbounded bill. Exercised directly — the HTTP plumbing around it is Craft's
 * concern, the budget guard is ours.
 */
class SearchControllerTest extends Unit
{
    private SearchController $controller;
    private ReflectionMethod $enforceRateLimit;
    private ReflectionMethod $intParam;

    protected function _before(): void
    {
        $this->controller = new SearchController('search', Plugin::getInstance());

        $this->enforceRateLimit = new ReflectionMethod(SearchController::class, 'enforceRateLimit');
        $this->enforceRateLimit->setAccessible(true);

        $this->intParam = new ReflectionMethod(SearchController::class, 'intParam');
        $this->intParam->setAccessible(true);

        // Each test gets a clean throttle bucket.
        Craft::$app->getCache()->flush();
    }

    private function enforce(Settings $settings): void
    {
        $this->enforceRateLimit->invoke($this->controller, $settings);
    }

    private function settings(int $limit, int $window = 60): Settings
    {
        $settings = new Settings();
        $settings->publicSearchRateLimit = $limit;
        $settings->publicSearchRateWindow = $window;

        return $settings;
    }

    public function testRequestsUnderTheLimitAreAllowed(): void
    {
        $settings = $this->settings(3);

        $this->enforce($settings);
        $this->enforce($settings);
        $this->enforce($settings);

        // Three allowed against a limit of three.
        $this->addToAssertionCount(1);
    }

    public function testExceedingTheLimitIsRejected(): void
    {
        $settings = $this->settings(2);

        $this->enforce($settings);
        $this->enforce($settings);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->enforce($settings);
    }

    public function testStaysRejectedOnceTripped(): void
    {
        $settings = $this->settings(1);
        $this->enforce($settings);

        $rejections = 0;
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->enforce($settings);
            } catch (TooManyRequestsHttpException) {
                $rejections++;
            }
        }

        $this->assertSame(3, $rejections);
    }

    public function testAZeroLimitDisablesThrottlingEntirely(): void
    {
        $settings = $this->settings(0);

        for ($i = 0; $i < 25; $i++) {
            $this->enforce($settings);
        }

        $this->addToAssertionCount(1);
    }

    public function testANegativeLimitAlsoDisablesThrottling(): void
    {
        $settings = $this->settings(-5);

        $this->enforce($settings);
        $this->enforce($settings);

        $this->addToAssertionCount(1);
    }

    public function testTheBucketExpiresWithTheWindow(): void
    {
        // A one-second window keeps the test quick while still crossing a real
        // window boundary.
        $settings = $this->settings(1, 1);
        $this->enforce($settings);

        sleep(2);

        // The window has rolled over, so this must be allowed again.
        $this->enforce($settings);
        $this->addToAssertionCount(1);
    }

    public function testLimitParamIsClampedToTheAllowedRange(): void
    {
        Craft::$app->getRequest()->setQueryParams(['limit' => '500']);
        $this->assertSame(100, $this->intParam->invoke($this->controller, 'limit'));

        Craft::$app->getRequest()->setQueryParams(['limit' => '0']);
        $this->assertSame(1, $this->intParam->invoke($this->controller, 'limit'));

        Craft::$app->getRequest()->setQueryParams(['limit' => '-10']);
        $this->assertSame(1, $this->intParam->invoke($this->controller, 'limit'));

        Craft::$app->getRequest()->setQueryParams(['limit' => '25']);
        $this->assertSame(25, $this->intParam->invoke($this->controller, 'limit'));
    }

    public function testLimitParamFallsBackToNullWhenAbsent(): void
    {
        Craft::$app->getRequest()->setQueryParams([]);
        $this->assertNull($this->intParam->invoke($this->controller, 'limit'));

        Craft::$app->getRequest()->setQueryParams(['limit' => '']);
        $this->assertNull($this->intParam->invoke($this->controller, 'limit'));
    }

    public function testAnonymousAccessIsScopedToTheSearchActions(): void
    {
        $reflection = new \ReflectionProperty(SearchController::class, 'allowAnonymous');
        $reflection->setAccessible(true);

        // Craft normalizes the declared list into an action => bitmask map.
        $allowed = $reflection->getValue($this->controller);

        $this->assertSame(['upload', 'similar'], array_keys($allowed));
    }

    public function testAdminActionsAreNotAnonymous(): void
    {
        $controller = new \justinholtweb\spectacles\controllers\AdminController(
            'admin',
            Plugin::getInstance()
        );

        $reflection = new \ReflectionProperty(
            \craft\web\Controller::class,
            'allowAnonymous'
        );
        $reflection->setAccessible(true);

        // reindex/analyze-asset queue work and must stay behind permissions.
        $this->assertEmpty($reflection->getValue($controller));
    }
}
