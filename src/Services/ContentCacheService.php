<?php

namespace KhalsaJio\ContentCreator\Services;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Cache\CacheFactory;

/**
 * Service for caching content structure data
 */
class ContentCacheService
{
    use Injectable;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var string
     */
    private $cacheName = 'ContentCreator_StructureCache';

    /**
     * Constructor
     *
     * @param CacheFactory $cacheFactory
     */
    public function __construct(CacheFactory $cacheFactory = null)
    {
        if ($cacheFactory === null) {
            $cacheFactory = Injector::inst()->get(CacheFactory::class);
        }
        $this->cache = $cacheFactory->create($this->cacheName);
    }
    
    /**
     * Set the cache name
     *
     * @param string $cacheName
     * @return $this
     */
    public function setCacheName(string $cacheName)
    {
        $this->cacheName = $cacheName;
        // Re-initialize the cache with the new name
        $cacheFactory = Injector::inst()->get(CacheFactory::class);
        $this->cache = $cacheFactory->create($this->cacheName);
        return $this;
    }
    
    /**
     * Get the cache name
     *
     * @return string
     */
    public function getCacheName(): string
    {
        return $this->cacheName;
    }

    /**
     * Generate a cache key for a DataObject
     *
     * @param DataObject $dataObject
     * @param string $prefix Optional prefix for the cache key
     * @return string
     */
    public function generateCacheKey(DataObject $dataObject, string $prefix = 'structure'): string
    {
        $cacheKeyRaw = sprintf(
            '%s_%s_%s_%s',
            $prefix,
            str_replace('\\', '_', $dataObject->ClassName), // Sanitize class name
            $dataObject->ID,
            strtotime($dataObject->LastEdited ?: '0') // Use LastEdited for cache busting
        );

        // PSR-16 allows: A-Z, a-z, 0-9, _, and .
        return preg_replace('/[^a-zA-Z0-9_.]/', '', $cacheKeyRaw);
    }

    /**
     * Get an item from the cache
     *
     * @param string $key
     * @return mixed|null Returns the cached value or null if not found
     */
    public function get(string $key)
    {
        if (!$this->cache->has($key)) {
            return null;
        }

        $cachedData = $this->cache->get($key);
        if (!$cachedData) {
            return null;
        }

        $data = unserialize($cachedData);
        return $data;
    }

    /**
     * Store an item in the cache
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl Time to live in seconds, null for default
     * @return bool True on success
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        return $this->cache->set($key, serialize($value), $ttl);
    }

    /**
     * Get an item from the cache or generate it using a callback function
     *
     * @param string $key The cache key
     * @param callable $callback Function to generate the value if not in cache
     * @param int|null $ttl Time to live in seconds, null for default
     * @return mixed The cached or freshly generated value
     */
    public function getOrCreate(string $key, callable $callback, ?int $ttl = null)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = call_user_func($callback);

        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Check if an item exists in the cache
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    /**
     * Delete an item from the cache
     *
     * @param string $key
     * @return bool True on success
     */
    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    /**
     * Clear the entire cache
     *
     * @return bool True on success
     */
    public function clear(): bool
    {
        return $this->cache->clear();
    }

    /**
     * Get the underlying cache interface
     *
     * @return CacheInterface
     */
    public function getCache(): CacheInterface
    {
        return $this->cache;
    }
}
