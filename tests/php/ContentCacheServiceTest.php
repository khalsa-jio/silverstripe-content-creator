<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\SapphireTest;
use KhalsaJio\ContentCreator\Services\ContentCacheService;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Injector\Injector;
use Psr\SimpleCache\CacheInterface;

class ContentCacheServiceTest extends SapphireTest
{
    /**
     * Test that we can generate cache keys properly
     */
    public function testGenerateCacheKey()
    {
        $cacheService = Injector::inst()->get(ContentCacheService::class);

        $mockObject = $this->getMockBuilder(\Page::class)
            ->setMethods(['getField'])
            ->getMock();

        $key = $cacheService->generateCacheKey($mockObject);

        $this->assertNotEmpty($key);
        $this->assertStringContainsString('Page', $key);

        // Test with a custom prefix
        $key2 = $cacheService->generateCacheKey($mockObject, 'custom');
        $this->assertStringStartsWith('custom', $key2);
    }

    /**
     * Test the cache set and get operations
     */
    public function testCacheSetGet()
    {
        $cacheService = Injector::inst()->get(ContentCacheService::class);

        // Clear cache first to ensure clean test
        $cacheService->clear();

        // Test data
        $testKey = 'test_key';
        $testValue = ['foo' => 'bar', 'test' => 123];

        // Set the value
        $result = $cacheService->set($testKey, $testValue);
        $this->assertTrue($result);

        // Check it exists
        $this->assertTrue($cacheService->has($testKey));

        // Retrieve it
        $retrieved = $cacheService->get($testKey);
        $this->assertEquals($testValue, $retrieved);

        // Delete it
        $deleted = $cacheService->delete($testKey);
        $this->assertTrue($deleted);

        // Verify it's gone
        $this->assertFalse($cacheService->has($testKey));
    }

    /**
     * Test the getOrCreate method
     */
    public function testGetOrCreate()
    {
        $cacheService = Injector::inst()->get(ContentCacheService::class);

        // Clear cache first to ensure clean test
        $cacheService->clear();

        $testKey = 'lazy_key';
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return "Generated value {$callCount}";
        };

        // First call should execute the callback
        $value1 = $cacheService->getOrCreate($testKey, $callback);
        $this->assertEquals('Generated value 1', $value1);
        $this->assertEquals(1, $callCount);

        // Second call should return the cached value without running the callback again
        $value2 = $cacheService->getOrCreate($testKey, $callback);
        $this->assertEquals('Generated value 1', $value2); // Still the first value
        $this->assertEquals(1, $callCount); // Callback not called again
    }
}
