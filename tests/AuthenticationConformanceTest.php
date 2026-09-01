<?php

namespace Sifrious\AccountsClient\Tests;

use PHPUnit\Framework\TestCase;
use Sifrious\AccountsClient\Testing\AuthenticationConformance;
use Sifrious\AccountsClient\Testing\ConsumerUnderTest;
use Sifrious\AccountsClient\Tests\Conformance\InMemoryConsumer;

/**
 * Runs the shared suite against the reference consumer, so the kit itself is
 * proven before any product adopts it.
 */
final class AuthenticationConformanceTest extends TestCase
{
    use AuthenticationConformance;

    private ?InMemoryConsumer $consumer = null;

    protected function consumerUnderTest(): ConsumerUnderTest
    {
        return $this->consumer ??= new InMemoryConsumer;
    }

    protected function tearDown(): void
    {
        $this->consumer = null;
        parent::tearDown();
    }
}
