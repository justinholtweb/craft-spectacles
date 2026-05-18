<?php

namespace justinholtweb\spectacles\services;

use Craft;
use craft\helpers\Json;
use RuntimeException;
use yii\httpclient\Client;

/**
 * Tiny shared JSON-over-HTTP helper. Used by every external provider — keeps
 * timeout, error logging, and JSON decoding in one place.
 */
trait Http
{
    private function jsonRequest(
        string $baseUrl,
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        int $timeout = 60,
    ): array {
        $client = new Client(['baseUrl' => rtrim($baseUrl, '/')]);
        $request = $client->createRequest()
            ->setMethod($method)
            ->setUrl(ltrim($path, '/'))
            ->setHeaders(array_merge(['Content-Type' => 'application/json'], $headers))
            ->setOptions(['timeout' => $timeout]);

        if ($body) {
            $request->setContent(Json::encode($body));
        }

        $response = $client->send($request);

        if (!$response->isOk) {
            $errorBody = is_array($response->data) ? Json::encode($response->data) : (string)$response->content;
            Craft::error(static::class . " HTTP {$response->statusCode}: {$errorBody}", __METHOD__);
            throw new RuntimeException(static::class . " request failed: HTTP {$response->statusCode}");
        }

        if (!is_array($response->data)) {
            throw new RuntimeException(static::class . ' response was not JSON.');
        }

        return $response->data;
    }
}
