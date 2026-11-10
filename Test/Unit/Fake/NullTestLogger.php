<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Test\Unit\Fake;

use Psr\Log\LoggerInterface;

/**
 * Logger that appends every record to a public array.
 */
class NullTestLogger implements LoggerInterface
{
    /**
     * @var array<int, array{level: string, message: string}>
     */
    public $records = [];

    /**
     * @param string $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = [])
    {
        $this->records[] = ['level' => 'debug', 'message' => (string)$message];
    }

    /**
     * @param string $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = [])
    {
        $this->records[] = ['level' => 'warning', 'message' => (string)$message];
    }

    /**
     * @param string $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = [])
    {
        $this->records[] = ['level' => 'error', 'message' => (string)$message];
    }
}
