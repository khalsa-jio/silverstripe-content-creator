<?php

namespace KhalsaJio\ContentCreator\Tests;

use PHPUnit\Framework\TestCase;
use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use KhalsaJio\AI\Nexus\LLMClient;

/**
 * Basic unit tests for ContentGeneratorService
 */
class ContentGeneratorServiceUnitTest extends TestCase
{
    private $service;
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

        $this->service = new ContentGeneratorService($this->mockLLMClient);
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
        $method = new \ReflectionMethod(ContentGeneratorService::class, 'isContentField');
        $method->setAccessible(true);

        // Test the method results
        $this->assertTrue($method->invoke($this->service, $textField), 'TextField should be considered a content field');
        $this->assertTrue($method->invoke($this->service, $htmlField), 'HTMLEditorField should be considered a content field');
        $this->assertFalse($method->invoke($this->service, $numericField), 'NumericField should not be considered a content field');
        $this->assertFalse($method->invoke($this->service, $checkboxField), 'CheckboxField should not be considered a content field');
    }
}
