<?php

namespace KhalsaJio\ContentCreator\Tests;

use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use KhalsaJio\AI\Nexus\LLMClient;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Injector\Injector;
use ReflectionMethod;

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
        $textField = \SilverStripe\Forms\TextField::create('TestTextField');
        $htmlField = \SilverStripe\Forms\HTMLEditor\HTMLEditorField::create('Content');
        $numericField = \SilverStripe\Forms\NumericField::create('TestNumericField');
        $checkboxField = \SilverStripe\Forms\CheckboxField::create('TestCheckboxField');

        // Test the method
        $this->assertTrue($method->invoke($service, $textField), 'TextField should be considered a content field');
        $this->assertTrue($method->invoke($service, $htmlField), 'HTMLEditorField should be considered a content field');
        $this->assertFalse($method->invoke($service, $numericField), 'NumericField should not be considered a content field');
        $this->assertFalse($method->invoke($service, $checkboxField), 'CheckboxField should not be considered a content field');
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

        // Create the service with the mock LLM client
        $service = new ContentGeneratorService($mockLLMClient);

        // Create a test page
        $page = \Page::create();
        $page->Title = 'Test Page';
        $page->Content = '<p>Test content</p>';
        $page->write();

        // Get the field structure
        $structure = $service->getPageFieldStructure($page);

        // Assert that we have some fields
        $this->assertNotEmpty($structure, 'Page field structure should not be empty');

        // Look for specific fields we know should be there
        $contentFieldFound = false;
        $titleFieldFound = false;

        foreach ($structure as $field) {
            if ($field['name'] === 'Content') {
                $contentFieldFound = true;
            }
            if ($field['name'] === 'Title') {
                $titleFieldFound = true;
            }
        }

        $this->assertTrue($contentFieldFound, 'Content field should be in the field structure');
        $this->assertTrue($titleFieldFound, 'Title field should be in the field structure');
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
