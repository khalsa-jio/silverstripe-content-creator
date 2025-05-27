# Content Creator Caching

The SilverStripe Content Creator module uses caching to improve performance when retrieving data object structure information. This document explains how the caching system works and how to configure it.

## Overview

The caching system is implemented through the `ContentCacheService` class. This service:

- Caches the structure of DataObjects so they don't need to be regenerated for every request
- Automatically invalidates cache entries when a DataObject is updated
- Provides helper methods for generating cache keys and retrieving/storing cached data

## Configuration

The cache configuration is defined in `app/_config/cache.yml`. By default, cache entries expire after 1 hour, but you can modify this by changing the `defaultLifetime` parameter.

```yaml
SilverStripe\Core\Injector\Injector:
  Psr\SimpleCache\CacheInterface.ContentCreator_StructureCache:
    factory: SilverStripe\Core\Cache\CacheFactory
    constructor:
      namespace: "ContentCreator_StructureCache"
      defaultLifetime: 3600  # 1 hour default
```

For more information on SilverStripe's caching system, refer to:

- [SilverStripe Caching Documentation](https://docs.silverstripe.org/en/4/developer_guides/performance/caching/)

## Clearing the Cache

The cache is automatically cleared when you run `?flush=1` or `dev/build?flush=1`.

To manually clear the cache, you can use the `FlushContentCreatorCache` task:

```bash
vendor/bin/sake dev/tasks/FlushContentCreatorCache
```

## How Cache Keys Are Generated

Cache keys are generated using:

1. The DataObject's class name
2. The DataObject's ID
3. The DataObject's last edited timestamp

This ensures that each unique object gets its own cache entry and the cache is automatically invalidated when the object is updated.

## For Developers

If you're extending the Content Creator module, you can access the cache service through dependency injection:

```php
use KhalsaJio\ContentCreator\Services\ContentCacheService;

class MyCustomService 
{
    private $cacheService;
    
    public function __construct(ContentCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }
    
    public function doSomething($dataObject)
    {
        $cacheKey = $this->cacheService->generateCacheKey($dataObject, 'my_custom_prefix');
        
        return $this->cacheService->getOrCreate($cacheKey, function() use ($dataObject) {
            // Expensive operation here
            return $expensiveResult;
        });
    }
}
```
