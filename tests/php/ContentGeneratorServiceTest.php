<?php

namespace KhalsaJio\ContentCreator\Tests;

use KhalsaJio\ContentCreator\Services\ContentAIService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Injector\Injector;
use ReflectionMethod;

class ContentGeneratorServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = './fixtures/ContentGeneratorServiceTest.yml';

    /**
     * Test that formatStructureForPrompt formats the structure correctly
     */
    public function testFormatStructureForPrompt()
    {
        $service = Injector::inst()->get(ContentAIService::class);

        // Make the private method accessible
        $method = new ReflectionMethod(ContentAIService::class, 'formatStructureForPrompt');
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
