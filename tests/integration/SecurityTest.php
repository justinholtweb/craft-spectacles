<?php

namespace justinholtweb\spectacles\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use justinholtweb\spectacles\controllers\AdminController;
use justinholtweb\spectacles\controllers\SearchController;
use justinholtweb\spectacles\models\Settings;
use justinholtweb\spectacles\Plugin;
use justinholtweb\spectacles\records\ImageMetadata;
use justinholtweb\spectacles\services\Similarity;
use justinholtweb\spectacles\tests\_support\AssetHelper;
use yii\base\Event;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;

/**
 * What the public endpoints may reveal, and what the admin actions may be tricked into.
 *
 * Until 5.1.0 public search was on by default and searched every indexed volume, so an anonymous
 * visitor got titles, filenames, URLs and AI descriptions from internal volumes — and could name
 * any asset id as the source. And the re-index and analyse actions were GET links: an <img src> on
 * any page an admin visited queued a paid analysis of every image.
 */
class SecurityTest extends Unit
{
    use AssetHelper;

    protected function _before(): void
    {
        Plugin::getInstance()->setSettings([
            'vectorIndex' => Settings::INDEX_AUTO,
            'defaultResultLimit' => 12,
            'minSimilarityScore' => 0.5,
            'allowPublicSearch' => true,
            'publicVolumeUids' => [],
            'publicSearchRateLimit' => 0,
        ]);
        Craft::$app->getCache()->flush();
    }

    private function seed(array $embedding, string $volumeHandle = 'spectaclesTest'): int
    {
        $asset = $this->createImageAsset('test.png', $volumeHandle);
        $record = new ImageMetadata([
            'assetId' => $asset->id,
            'description' => 'an internal photo',
            'embedding' => $embedding,
            'embeddingModel' => 'test-model',
        ]);
        $this->assertTrue($record->save());

        return $asset->id;
    }

    public function testPublicSearchIsOffAndNoVolumeIsPublicByDefault(): void
    {
        $settings = new Settings();

        $this->assertFalse($settings->allowPublicSearch);
        $this->assertSame([], $settings->publicVolumeUids);
        $this->assertSame([], $settings->getPublicVolumeIds());
    }

    public function testAVolumeRestrictionFiltersTheHits(): void
    {
        $id = $this->seed([1.0, 0.0, 0.0]);
        $similarity = new Similarity();
        $volumeId = $this->testVolume()->id;

        $this->assertSame([$id], array_map(fn($r) => $r['asset']->id, $similarity->similarToVector([1.0, 0.0, 0.0], volumeIds: [$volumeId])));
        $this->assertSame([], $similarity->similarToVector([1.0, 0.0, 0.0], volumeIds: [$volumeId + 100000]));
        $this->assertSame([], $similarity->similarToVector([1.0, 0.0, 0.0], volumeIds: []));
    }

    public function testARestrictionStillFillsTheLimit(): void
    {
        // The closest matches are in a volume that isn't public, so a search that took only the
        // top three hits and then filtered them would come back empty.
        foreach (range(1, 3) as $_) {
            $this->seed([1.0, 0.0, 0.0], 'spectaclesInternal');
        }
        foreach (range(1, 4) as $_) {
            $this->seed([0.9, 0.1, 0.0]);
        }

        $results = (new Similarity())->similarToVector([1.0, 0.0, 0.0], 3, volumeIds: [$this->testVolume()->id]);

        $this->assertCount(3, $results);
    }

    public function testThePublicSimilarEndpointRefusesAnAssetOutsideThePublicVolumes(): void
    {
        $id = $this->seed([1.0, 0.0, 0.0]);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        $controller = new SearchController('search', Plugin::getInstance());

        // The asset is real and analysed, but its volume isn't public: "not found".
        $this->expectException(NotFoundHttpException::class);
        $controller->actionSimilar($id);
    }

    public function testThePublicSimilarEndpointAnswersForAPublicVolume(): void
    {
        $id = $this->seed([1.0, 0.0, 0.0]);
        $this->seed([1.0, 0.0, 0.0]);
        Plugin::getInstance()->setSettings(['publicVolumeUids' => [$this->testVolume()->uid]]);
        Craft::$app->getRequest()->getHeaders()->set('Accept', 'application/json');
        $controller = new SearchController('search', Plugin::getInstance());

        $response = $controller->actionSimilar($id);

        $this->assertNotEmpty($response->data['results']);
    }

    public function testAForgedForwardedForDoesNotBuyAFreshBudget(): void
    {
        $settings = new Settings();
        $settings->publicSearchRateLimit = 1;
        $settings->publicSearchRateWindow = 60;
        $controller = new SearchController('search', Plugin::getInstance());
        $enforce = new \ReflectionMethod(SearchController::class, 'enforceRateLimit');
        $request = Craft::$app->getRequest();
        $headers = $request->getHeaders();

        // Craft's default: a direct connection, and `trustedHosts` left at "any" — which makes
        // getUserIP() believe whatever X-Forwarded-For says. That is the state the old limiter
        // keyed on, so prove it first.
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $request->trustedHosts = ['any'];
        $headers->set('X-Forwarded-For', '203.0.113.1');
        $this->assertSame('203.0.113.1', $request->getUserIP(), 'precondition: the header is believed');
        $enforce->invoke($controller, $settings);

        // Craft memoizes the user IP per request; clear it so the second call reads the new header,
        // as a second real request would.
        (new \ReflectionProperty(\craft\web\Request::class, '_ipAddress'))->setValue($request, null);
        $headers->set('X-Forwarded-For', '203.0.113.2');
        $this->assertSame('203.0.113.2', $request->getUserIP(), 'precondition: a fresh address each time');
        $this->expectException(\yii\web\TooManyRequestsHttpException::class);
        $enforce->invoke($controller, $settings);
    }

    public function testReindexHasItsOwnPermission(): void
    {
        $event = new RegisterUserPermissionsEvent();
        Event::trigger(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, $event);

        $names = [];
        foreach ($event->permissions as $group) {
            $names = array_merge($names, array_keys($group['permissions']));
        }

        $this->assertContains(Plugin::PERMISSION_REINDEX, $names);
    }

    /**
     * @dataProvider adminActions
     */
    public function testAdminActionsRefuseGet(string $action): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Craft::$app->getRequest()->setBodyParams(['assetId' => 1]);
        $controller = new AdminController('admin', Plugin::getInstance());

        $this->expectException(MethodNotAllowedHttpException::class);
        $controller->$action();
    }

    public static function adminActions(): array
    {
        return [['actionReindex'], ['actionAnalyzeAsset']];
    }
}
