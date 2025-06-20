<?php

namespace KhalsaJio\ContentCreator\Tests;

use PHPUnit\Framework\TestCase;
use KhalsaJio\ContentCreator\Services\ContentStructureService;
use KhalsaJio\ContentCreator\Services\ContentAIService; 
use KhalsaJio\AI\Nexus\LLMClient;

/**
 * Basic unit tests for ContentGeneratorService
 */
class ContentGeneratorServiceUnitTest extends TestCase
{
    private $structureService;
    private $aiService;
    private $mockLLMClient;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock LLMClient
        $this->mockLLMClient = $this->getMockBuilder(LLMClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock the __call method since LLMClient uses magic methods
        $this->mockLLMClient->method('__call')
            ->with($this->equalTo('chat'), $this->anything())
            ->willReturn([
                'content' => json_encode([
                    'Title' => 'Generated Title',
                    'Content' => '<p>Generated content paragraph.</p>',
                    'MetaDescription' => 'Generated meta description'
                ])
            ]);

        // Create a mock for ContentCacheService
        $mockCacheService = $this->createMock(\KhalsaJio\ContentCreator\Services\ContentCacheService::class);
        
        // Create a mock for Logger
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);
        
        // Create service instances
        $this->structureService = new ContentStructureService($mockCacheService, $mockLogger);
        $this->aiService = new ContentAIService($this->mockLLMClient, $this->structureService, null, $mockCacheService, $mockLogger);
    }

    public function testIsContentFieldMethod()
    {
        // Create mock form fields
        $textField = $this->createMock(\SilverStripe\Forms\TextField::class);
        $textField->method('getName')->willReturn('TestTextField');

        $htmlField = $this->createMock(\SilverStripe\Forms\HTMLEditor\HTMLEditorField::class);
        $htmlField->method('getName')->willReturn('Content');

        $numericField = $this->createMock(\SilverStripe\Forms\NumericField::class);
        $numericField->method('getName')->willReturn('NumericField');

        $checkboxField = $this->createMock(\SilverStripe\Forms\CheckboxField::class);
        $checkboxField->method('getName')->willReturn('CheckboxField');

        // Access the private isContentField method using reflection
        $method = new \ReflectionMethod(ContentStructureService::class, 'isContentField');
        $method->setAccessible(true);

        // Test the method results
        $this->assertTrue($method->invoke($this->structureService, $textField), 'TextField should be considered a content field');
        $this->assertTrue($method->invoke($this->structureService, $htmlField), 'HTMLEditorField should be considered a content field');
        $this->assertTrue($method->invoke($this->structureService, $numericField), 'NumericField should be considered a content field');
        $this->assertTrue($method->invoke($this->structureService, $checkboxField), 'CheckboxField should be considered a content field');
    }
}
