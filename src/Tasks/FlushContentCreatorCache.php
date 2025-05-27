<?php

namespace KhalsaJio\ContentCreator\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\Injector\Injector;
use KhalsaJio\ContentCreator\Services\ContentCacheService;

/**
 * Task to flush the Content Creator cache
 */
class FlushContentCreatorCache extends BuildTask
{
    protected $title = 'Flush Content Creator Cache';

    protected $description = 'Clears all cached structure data used by the Content Creator module';

    private static $segment = 'FlushContentCreatorCache';

    public function run($request)
    {
        $cacheService = Injector::inst()->get(ContentCacheService::class);

        $success = $cacheService->clear();

        if ($success) {
            echo "<p>Content Creator cache has been successfully cleared.</p>";
        } else {
            echo "<p>Failed to clear Content Creator cache.</p>";
        }
    }
}
