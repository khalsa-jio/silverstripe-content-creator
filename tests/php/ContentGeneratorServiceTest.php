<?php

namespace KhalsaJio\ContentCreator\Tests;

use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use KhalsaJio\AI\Nexus\LLMClient;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Injector\Injector;
use ReflectionMethod;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;

class ContentGeneratorServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = 'ContentGeneratorServiceTest.yml';

    /**
     * Test the isContentField method
     */
    public function testIsContentField()
    {
        $service = Injector::inst()->get(ContentGeneratorService::class);

        // Make the private method accessible
        $method = new ReflectionMethod(ContentGeneratorService::class, 'isContentField');
        $method->setAccessible(true);

        // Create test fields
        $textField = TextField::create('TestTextField');
        $htmlField = HTMLEditorField::create('Content');
        $numericField = NumericField::create('TestNumericField');
        $checkboxField = CheckboxField::create('TestCheckboxField');

        // Test the method
        $this->assertTrue($method->invoke($service, $textField), 'TextField should be considered a content field');
        $this->assertTrue($method->invoke($service, $htmlField), 'HTMLEditorField should be considered a content field');
        $this->assertTrue($method->invoke($service, $numericField), 'NumericField should be considered a content field');
        $this->assertTrue($method->invoke($service, $checkboxField), 'CheckboxField should be considered a content field');
    }

    /**
     * Test that getPageFieldStructure returns fields for a page
     */
    public function testGetPageFieldStructure()
    {
        // Create a mock LLM client
        $mockLLMClient = $this->getMockBuilder(LLMClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock the __call method since LLMClient uses magic methods
        $mockLLMClient->method('__call')
            ->willReturn([
                'content' => json_encode([
                    'Title' => 'Generated Title',
                    'Content' => 'Generated content'
                ])
            ]);

        // Create a mock ContentCacheService as well
        $mockCacheService = $this->createMock(\KhalsaJio\ContentCreator\Services\ContentCacheService::class);
        $mockCacheService->method('generateCacheKey')->willReturn('test_key');
        $mockCacheService->method('get')->willReturn(null);
        $mockCacheService->method('getOrCreate')->willReturnCallback(function($key, $callback) {
            return $callback();
        });

        // Mock the logger
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);

        // Create the service with all mock dependencies
        $service = new ContentGeneratorService($mockLLMClient, $mockCacheService, $mockLogger);

        // Create a test page with specific fields that we expect
        $page = new \Page();
        $page->ID = 123;
        $page->Title = 'Test Page';
        $page->Content = '<p>Test content</p>';
        $page->write();

        // Get the field structure
        $structure = $service->getPageFieldStructure($page);

        // Assert that we have some fields
        $this->assertNotEmpty($structure, 'Page field structure should not be empty');

        // Check that fields have the expected structure
        if (!empty($structure)) {
            $sampleField = $structure[0];
            $this->assertArrayHasKey('name', $sampleField, 'Field should have a name');
            $this->assertArrayHasKey('title', $sampleField, 'Field should have a title');
            $this->assertArrayHasKey('type', $sampleField, 'Field should have a type');
        }
    }

    /**
     * Test that formatStructureForPrompt formats the structure correctly
     */
    public function testFormatStructureForPrompt()
    {
        $service = Injector::inst()->get(ContentGeneratorService::class);

        // Make the private method accessible
        $method = new ReflectionMethod(ContentGeneratorService::class, 'formatStructureForPrompt');
        $method->setAccessible(true);

        $structure = [
            [
                'name' => 'Title',
                'title' => 'Title',
                'type' => 'SilverStripe\\Forms\\TextField',
                'description' => 'The page title'
            ],
            [
                'name' => 'Content',
                'title' => 'Content',
                'type' => 'SilverStripe\\Forms\\HTMLEditor\\HTMLEditorField',
                'description' => 'Main content'
            ]
        ];

        $formatted = $method->invoke($service, $structure);

        $this->assertStringContainsString('Title (Title)', $formatted, 'Formatted structure should contain Title field');
        $this->assertStringContainsString('Content (Content)', $formatted, 'Formatted structure should contain Content field');
        $this->assertStringContainsString('The page title', $formatted, 'Formatted structure should contain field description');
        $this->assertStringContainsString('Main content', $formatted, 'Formatted structure should contain field description');
    }
}
