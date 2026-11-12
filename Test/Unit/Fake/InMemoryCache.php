<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Fake;

use Citecue\Delivery\Model\Cache\Type;

/**
 * In-memory stand-in for the delivery cache type, with tag awareness and a
 * way to simulate TTL expiry.
 */
class InMemoryCache extends Type
{
    /**
     * @var array<string, array{data: string, tags: array, expired: bool}>
     */
    private $items = [];

    /**
     * Intentionally skips the parent constructor (no cache frontend pool).
     */
    public function __construct()
    {
    }

    /**
     * @param string $identifier
     * @return string|false
     */
    public function load($identifier)
    {
        if (!isset($this->items[$identifier]) || $this->items[$identifier]['expired']) {
            return false;
        }
        return $this->items[$identifier]['data'];
    }

    /**
     * @param string $data
     * @param string $identifier
     * @param array $tags
     * @param int|null $lifeTime
     * @return bool
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $this->items[$identifier] = ['data' => (string)$data, 'tags' => $tags, 'expired' => false];
        return true;
    }

    /**
     * @param string $identifier
     * @return bool
     */
    public function remove($identifier)
    {
        unset($this->items[$identifier]);
        return true;
    }

    /**
     * @param string $mode
     * @param array $tags
     * @return bool
     */
    public function clean($mode = 'all', array $tags = [])
    {
        foreach ($this->items as $id => $item) {
            if ($mode === 'all' || ($mode === 'matchingTag' && array_diff($tags, $item['tags']) === [])) {
                unset($this->items[$id]);
            }
        }
        return true;
    }

    /**
     * Marks every live entry whose id starts with the prefix as expired (TTL passage).
     *
     * @param string $prefix
     * @return void
     */
    public function expirePrefix(string $prefix): void
    {
        foreach ($this->items as $id => $item) {
            if (strpos((string)$id, $prefix) === 0) {
                $this->items[$id]['expired'] = true;
            }
        }
    }

    /**
     * Ids of all live (non-expired) entries.
     *
     * @return string[]
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->items as $id => $item) {
            if (!$item['expired']) {
                $keys[] = (string)$id;
            }
        }
        return $keys;
    }

    /**
     * @param string $id
     * @param string $data
     * @param array $tags
     * @return void
     */
    public function putRaw(string $id, string $data, array $tags = []): void
    {
        $this->items[$id] = ['data' => $data, 'tags' => $tags, 'expired' => false];
    }

    /**
     * @param string $id
     * @return string|null
     */
    public function getRaw(string $id): ?string
    {
        $value = $this->load($id);
        return $value === false ? null : $value;
    }

    /**
     * @param string $id
     * @return array
     */
    public function tagsOf(string $id): array
    {
        return $this->items[$id]['tags'] ?? [];
    }
}
