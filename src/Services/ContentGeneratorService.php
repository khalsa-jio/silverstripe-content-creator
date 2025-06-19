<?php

namespace KhalsaJio\ContentCreator\Services;

use Exception;
use SilverStripe\ORM\DataObject;
use KhalsaJio\AI\Nexus\LLMClient;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * Service for generating content based on page fields or Elemental blocks
 * 
 * This class now acts as a façade for the refactored services:
 * - ContentStructureService: Handles field structure analysis
 * - ContentAIService: Handles AI content generation
 * - ContentPopulatorService: Handles content population
 * 
 * This façade maintains backward compatibility with existing code.
 */
class ContentGeneratorService
{
    use Injectable;
    use Configurable;
    use Extensible;

    /**
     * @var ContentStructureService
     */
    private $structureService;

    /**
     * @var ContentAIService 
     */
    private $generatorService;

    /**
     * @var ContentPopulatorService
     */
    private $populatorService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Constructor
     * 
     * @param ContentStructureService|null $structureService
     * @param ContentAIService|null $generatorService
     * @param ContentPopulatorService|null $populatorService
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ContentStructureService $structureService = null,
        ContentAIService $generatorService = null,  
        ContentPopulatorService $populatorService = null,
        LoggerInterface $logger = null
    ) {
        $this->structureService = $structureService ?: Injector::inst()->get(ContentStructureService::class);
        $this->generatorService = $generatorService ?: Injector::inst()->get(ContentAIService::class);
        $this->populatorService = $populatorService ?: Injector::inst()->get(ContentPopulatorService::class);
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Get the field structure for a given DataObject
     * Delegates to ContentStructureService
     *
     * @param DataObject $dataObject
     * @param bool $refreshCache Whether to refresh the cache, defaults to false
     * @return array The field structure
     * @throws Exception If there's an error generating the field structure
     */
    public function getPageFieldStructure(DataObject $dataObject, bool $refreshCache = false): array
    {
        return $this->structureService->getPageFieldStructure($dataObject, $refreshCache);
    }
    
    /**
     * Get the field structure for a specific object
     * Delegates to ContentStructureService
     *
     * @param mixed $objectOrClass DataObject instance or class name string
     * @param bool $includeElementalAreas Whether to include elemental areas in the structure
     * @param int $depth The current recursion depth
     * @return array The field structure
     */
    public function getObjectFieldStructure($objectOrClass, bool $includeElementalAreas = true, int $depth = 0): array
    {
        return $this->structureService->getObjectFieldStructure($objectOrClass, $includeElementalAreas, $depth);
    }

    /**
     * Generate content for a page based on a prompt
     * Delegates to ContentAIService
     *
     * @param DataObject $page
     * @param string $prompt
     * @return array The generated content in a structured format
     * @throws Exception
     */
    public function generateContent(DataObject $page, string $prompt): array
    {
        return $this->generatorService->generateContent($page, $prompt);
    }

    /**
     * Generate content in a streaming fashion
     * Delegates to ContentAIService
     *
     * @param DataObject $dataObject
     * @param string $prompt
     * @param int $maxTokens
     * @param float $temperature
     * @param callable $chunkCallback
     * @param callable $completeCallback
     * @param callable $errorCallback
     * @throws Exception
     */
    public function generateStreamContent(
        DataObject $dataObject,
        string $prompt,
        int $maxTokens = 4000,
        float $temperature = 0.7,
        callable $chunkCallback = null,
        callable $completeCallback = null,
        callable $errorCallback = null
    ): void {
        $this->generatorService->generateStreamContent(
            $dataObject,
            $prompt,
            $maxTokens,
            $temperature,
            $chunkCallback,
            $completeCallback,
            $errorCallback
        );
    }

    /**
     * Populate a DataObject with generated content
     * Delegates to ContentPopulatorService
     *
     * @param DataObject $dataObject
     * @param array $generatedContent
     * @param bool $write
     * @return DataObject
     */
    public function populateContent(DataObject $dataObject, array $generatedContent, bool $write = true): DataObject
    {
        return $this->populatorService->populateContent($dataObject, $generatedContent, $write);
    }
    
    /**
     * Create a complete content generation and population workflow
     * Uses both generator and populator services
     *
     * @param DataObject $dataObject
     * @param string $prompt
     * @param bool $write
     * @return DataObject
     * @throws Exception
     */
    public function generateAndPopulateContent(DataObject $dataObject, string $prompt, bool $write = true): DataObject
    {
        // Generate content using the generator service
        $generatedContent = $this->generateContent($dataObject, $prompt);
        
        // Populate the object with the populator service
        return $this->populateContent($dataObject, $generatedContent, $write);
    }
    
    /**
     * Parse generated content from string to structured array
     * Delegates to ContentAIService
     *
     * @param string $content
     * @return array
     */
    public function parseGeneratedContent(string $content): array 
    {
        return $this->generatorService->parseGeneratedContent($content);
    }
    
    /**
     * Build a system prompt for AI content generation
     * Delegates to ContentAIService
     *
     * @param DataObject $dataObject
     * @return string
     */
    public function buildSystemPrompt(DataObject $dataObject): string 
    {
        return $this->generatorService->buildSystemPrompt($dataObject);
    }
    
    /**
     * Format the page structure in a way that can be included in a prompt
     * Delegates to ContentAIService
     *
     * @param array $structure
     * @return string
     */
    protected function formatStructureForPrompt(array $structure): string
    {
        return $this->generatorService->formatStructureForPrompt($structure);
    }
}