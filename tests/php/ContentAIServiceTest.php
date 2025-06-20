<?php

namespace KhalsaJio\ContentCreator\Tests;

use KhalsaJio\ContentCreator\Services\ContentAIService;
use KhalsaJio\ContentCreator\Services\ContentStructureService;
use KhalsaJio\ContentCreator\Services\ContentPopulatorService;
use KhalsaJio\AI\Nexus\LLMClient;
use SilverStripe\Dev\SapphireTest;

class ContentAIServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $fixture_file = './fixtures/ContentGeneratorServiceTest.yml';

    /**
     * Test that parseGeneratedContent parses the generated content correctly
     */
    public function testParseGeneratedContent()
    {
        // Create mock dependencies
        $mockLLMClient = $this->createMock(LLMClient::class);
        $mockStructureService = $this->createMock(ContentStructureService::class);
        $mockPopulatorService = $this->createMock(ContentPopulatorService::class);
        $mockCacheService = $this->createMock(\KhalsaJio\ContentCreator\Services\ContentCacheService::class);
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);

        // Create service with mocks
        $service = new ContentAIService(
            $mockLLMClient,
            $mockStructureService,
            $mockPopulatorService,
            $mockCacheService,
            $mockLogger
        );

        // Test with a properly formatted yaml string
        $yamlContent = <<<EOT
Title: Generated Title
Content: Generated content for the page
EOT;

        $parsed = $service->parseGeneratedContent($yamlContent);

        $this->assertIsArray($parsed, 'Parsed content should be an array');
        $this->assertArrayHasKey('Title', $parsed, 'Parsed content should have Title field');
        $this->assertArrayHasKey('Content', $parsed, 'Parsed content should have Content field');
        $this->assertEquals('Generated Title', $parsed['Title'], 'Title should be parsed correctly');
        $this->assertEquals('Generated content for the page', $parsed['Content'], 'Content should be parsed correctly');
        
        // Test with yaml in code block syntax
        $codeBlockContent = <<<EOT
```yaml
Title: Code Block Title
Content: Content inside a code block
```
EOT;

        $parsedCodeBlock = $service->parseGeneratedContent($codeBlockContent);
        $this->assertIsArray($parsedCodeBlock, 'Parsed code block content should be an array');
        $this->assertArrayHasKey('Title', $parsedCodeBlock, 'Parsed code block should have Title field');
        $this->assertEquals('Code Block Title', $parsedCodeBlock['Title'], 'Code block title should be parsed correctly');
        
        // Test with non-parsable content (fallback case)
        $nonYamlContent = "This is just regular text without any YAML structure";
        $parsedNonYaml = $service->parseGeneratedContent($nonYamlContent);
        
        $this->assertIsArray($parsedNonYaml, 'Non-YAML should be returned as array');
        $this->assertArrayHasKey('content', $parsedNonYaml, 'Non-YAML should be placed in content key');
        $this->assertEquals($nonYamlContent, $parsedNonYaml['content'], 'Original content should be preserved');
    }

    /**
     * Test that buildSystemPrompt builds the system prompt correctly
     */
    public function testBuildSystemPrompt()
    {
        // Create mock structure service that returns a predetermined structure
        $mockStructureService = $this->createMock(ContentStructureService::class);
        $mockStructureService->method('getPageFieldStructure')->willReturn([
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
        ]);

        // Create other mock dependencies
        $mockLLMClient = $this->createMock(LLMClient::class);
        $mockPopulatorService = $this->createMock(ContentPopulatorService::class);
        $mockCacheService = $this->createMock(\KhalsaJio\ContentCreator\Services\ContentCacheService::class);
        $mockLogger = $this->createMock(\Psr\Log\LoggerInterface::class);

        // Create service with mocks
        $service = new ContentAIService(
            $mockLLMClient,
            $mockStructureService,
            $mockPopulatorService,
            $mockCacheService,
            $mockLogger
        );

        // Mock the formatStructureForPrompt method
        $formatMethodMock = $this->getMockBuilder(ContentAIService::class)
            ->setConstructorArgs([
                $mockLLMClient,
                $mockStructureService,
                $mockPopulatorService,
                $mockCacheService,
                $mockLogger
            ])
            ->setMethods(['formatStructureForPrompt'])
            ->getMock();

        $formatMethodMock->method('formatStructureForPrompt')
            ->willReturn('Title (Title): The page title\nContent (Content): Main content');

        // Create test page
        $page = new \Page();
        $page->Title = 'Test Page';

        // Get system prompt
        $systemPrompt = $service->buildSystemPrompt($page);

        $this->assertIsString($systemPrompt, 'System prompt should be a string');
        $this->assertStringContainsString('yaml format', strtolower($systemPrompt), 'System prompt should mention YAML format');
    }
}
