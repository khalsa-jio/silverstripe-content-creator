<?php

namespace KhalsaJio\ContentCreator\Tests;

use KhalsaJio\ContentCreator\Services\ContentPopulatorService;
use SilverStripe\Dev\SapphireTest;

class ContentPopulatorServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = './fixtures/ContentGeneratorServiceTest.yml';

    /**
     * Test that populateContent method populates a DataObject with generated content
     */
    public function testPopulateContent()
    {
        // Create mock cache service
        $mockCacheService = $this->createMock(\KhalsaJio\ContentCreator\Services\ContentCacheService::class);

        // Mock logger
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);

        // Create service with mocks
        $service = new ContentPopulatorService($mockCacheService, $mockLogger);

        // Create a test page
        $page = new \Page();
        $page->Title = 'Original Title';
        $page->Content = '<p>Original content</p>';
        $page->write();

        // Create generated content array
        $generatedContent = [
            'Title' => 'New Generated Title',
            'Content' => '<p>New generated content</p>'
        ];

        // Populate the content
        $populatedPage = $service->populateContent($page, $generatedContent);

        // Test that the content was populated correctly
        $this->assertEquals('New Generated Title', $populatedPage->Title, 'Title should be updated with generated content');
        $this->assertEquals('<p>New generated content</p>', $populatedPage->Content, 'Content should be updated with generated content');
    }
}
