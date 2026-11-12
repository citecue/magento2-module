<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Fake;

use Citecue\Delivery\Model\Api\Client;

/**
 * Scripted API client: records every call and replays queued responses.
 */
class FakeClient extends Client
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public $calls = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private $queue = [];

    /**
     * Intentionally skips the parent constructor.
     */
    public function __construct()
    {
    }

    /**
     * @param array $response status/body/etag/mode/content_type (missing keys default to null)
     * @return void
     */
    public function queueResponse(array $response): void
    {
        $this->queue[] = $response + [
            'status' => 0,
            'body' => null,
            'etag' => null,
            'mode' => null,
            'content_type' => null,
        ];
    }

    /**
     * @param string $url
     * @param string $crawlerId
     * @param string|null $etag
     * @param int|string|null $storeId
     * @param string|null $accept
     * @return array
     */
    public function fetchPage(
        string $url,
        string $crawlerId,
        ?string $etag,
        $storeId = null,
        ?string $accept = null
    ): array {
        $this->calls[] = [
            'endpoint' => 'page',
            'url' => $url,
            'etag' => $etag,
            'accept' => $accept,
            'crawler' => $crawlerId,
        ];
        return $this->next();
    }

    /**
     * @param string|null $etag
     * @param int|string|null $storeId
     * @return array
     */
    public function fetchLlmsTxt(?string $etag, $storeId = null): array
    {
        $this->calls[] = [
            'endpoint' => 'llms',
            'url' => null,
            'etag' => $etag,
            'accept' => null,
            'crawler' => null,
        ];
        return $this->next();
    }

    /**
     * @return array
     */
    private function next(): array
    {
        if ($this->queue === []) {
            return ['status' => 0, 'body' => null, 'etag' => null, 'mode' => null, 'content_type' => null];
        }
        return array_shift($this->queue);
    }
}
