<?php

namespace KhalsaJio\ContentCreator\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;

/**
 * Base abstract class for all content services with shared functionality
 */
abstract class BaseContentService
{
    use Injectable;
    use Configurable;
    use Extensible;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ContentCacheService
     */
    protected $cacheService;

    /**
     * Constructor
     *
     * @param ContentCacheService|null $cacheService
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ContentCacheService $cacheService = null,
        LoggerInterface $logger = null
    ) {
        $this->cacheService = $cacheService ?: Injector::inst()->get(ContentCacheService::class);
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Format a field name into a human-readable title
     *
     * @param string $fieldName The field name to format
     * @return string The formatted title
     */
    protected function formatFieldTitle(string $fieldName): string
    {
        $title = preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' $0', $fieldName);
        $title = str_replace('_', ' ', $title);
        return ucfirst(trim($title));
    }

    /**
     * Unsanitise a model class name
     *
     * @param string $class
     * @return string
     */
    protected function unsanitiseClassName($class)
    {
        return str_replace('-', '\\', $class ?? '');
    }

    /**
     * Helper method to get short class name
     *
     * @param string $className
     * @return string
     */
    protected function getShortClassName(string $className): string
    {
        // If not a class name with namespace, return as is
        if (strpos($className, '\\') === false) {
            return $className;
        }

        $parts = explode('\\', $className);
        return end($parts);
    }
}