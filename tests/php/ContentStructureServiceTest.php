<?php

namespace KhalsaJio\ContentCreator\Tests;

use KhalsaJio\ContentCreator\Services\ContentStructureService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Injector\Injector;
use ReflectionMethod;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;

class ContentStructureServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = './fixtures/ContentGeneratorServiceTest.yml';

     /**
     * Test the isContentField method
     */
    public function testIsContentField()
    {
        $service = Injector::inst()->get(ContentStructureService::class);

        // Make the private method accessible
        $method = new ReflectionMethod(ContentStructureService::class, 'isContentField');
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
        // For this test, we'll create a partial mock that overrides generateFieldStructure
        $service = $this->getMockBuilder(ContentStructureService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateFieldStructure', 'getObjectFieldStructure'])
            ->getMock();

        // Set up the mock to return a static structure
        $mockStructure = [
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

        $service->expects($this->any())
            ->method('generateFieldStructure')
            ->willReturn($mockStructure);

        $service->expects($this->any())
            ->method('getObjectFieldStructure')
            ->willReturn($mockStructure);

        // Create a simple mock page
        $page = $this->createMock('SilverStripe\\CMS\\Model\\SiteTree');

        // Mock cache service
        $reflectionClass = new \ReflectionClass(ContentStructureService::class);
        $cacheProperty = $reflectionClass->getProperty('cacheService');
        $cacheProperty->setAccessible(true);

        $mockCache = $this->createMock('KhalsaJio\\ContentCreator\\Services\\ContentCacheService');
        $mockCache->method('generateCacheKey')->willReturn('test_key');
        $mockCache->method('getOrCreate')->willReturn($mockStructure);

        $cacheProperty->setValue($service, $mockCache);

        // Get the field structure
        $structure = $service->getPageFieldStructure($page);

        // Assert that we have the expected structure
        $this->assertIsArray($structure);
        $this->assertCount(2, $structure);
        $this->assertEquals('Title', $structure[0]['name']);
        $this->assertEquals('Content', $structure[1]['name']);
    }
}
