<?php

namespace KhalsaJio\ContentCreator\Tests;

use PHPUnit\Framework\TestCase;
use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use KhalsaJio\AI\Nexus\LLMClient;
use ReflectionMethod;
use Psr\Log\LoggerInterface;
use KhalsaJio\ContentCreator\Services\ContentCacheService;

/**
 * Unit tests for relationship field validation in ContentGeneratorService
 */
class RelationshipFieldValidationTest extends TestCase
{
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock LLM client
        $mockLLMClient = $this->getMockBuilder(LLMClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock the __call method since LLMClient uses magic methods
        $mockLLMClient->method('__call')
            ->willReturn([
                'content' => json_encode([
                    'Title' => 'Generated Title',
                    'Content' => '<p>Generated content paragraph.</p>',
                    'RelatedObject' => [
                        'Title' => 'Related Title'
                    ],
                    'RelatedItems' => [
                        ['Title' => 'Item 1'],
                        ['Title' => 'Item 2']
                    ]
                ])
            ]);
            
        // Mock the cache service and logger
        $mockCacheService = $this->createMock(ContentCacheService::class);
        $mockCacheService->method('generateCacheKey')->willReturn('test_key');
        $mockCacheService->method('getOrCreate')->willReturnCallback(function($key, $callback) {
            return $callback();
        });
        
        // Mock the logger
        $mockLogger = $this->createMock(LoggerInterface::class);

        $this->service = new ContentGeneratorService($mockLLMClient, $mockCacheService, $mockLogger);
    }

    /**
     * Test relationship field structure validation
     */
    public function testValidateRelationshipFieldStructure()
    {
        // Make the private method accessible
        $method = new ReflectionMethod(ContentGeneratorService::class, 'validateRelationshipFieldStructure');
        $method->setAccessible(true);

        // Test has_one validation with valid structure
        $validHasOne = [
            'Title' => 'Related Object',
            'Description' => 'Test description'
        ];
        $relationInfo = ['type' => 'has_one', 'class' => 'TestRelatedObject'];
        $errors = $method->invoke($this->service, 'TestRelatedObject', $validHasOne, $relationInfo);
        $this->assertEmpty($errors, 'Valid has_one structure should pass validation');

        // Test has_one validation with invalid structure
        $invalidHasOne = 'Not an object';
        $errors = $method->invoke($this->service, 'TestRelatedObject', $invalidHasOne, $relationInfo);
        $this->assertNotEmpty($errors, 'Invalid has_one structure should fail validation');

        // Test has_many validation with valid structure
        $validHasMany = [
            [
                'Title' => 'Related Object 1',
                'Description' => 'Test description 1'
            ],
            [
                'Title' => 'Related Object 2',
                'Description' => 'Test description 2'
            ]
        ];
        $relationInfo = ['type' => 'has_many', 'class' => 'TestRelatedObject'];
        $errors = $method->invoke($this->service, 'TestRelatedObjects', $validHasMany, $relationInfo);
        $this->assertEmpty($errors, 'Valid has_many structure should pass validation');

        // Test has_many validation with invalid structure (associative array)
        $invalidHasMany = [
            'item1' => [
                'Title' => 'Related Object 1'
            ],
            'item2' => [
                'Title' => 'Related Object 2'
            ]
        ];
        $errors = $method->invoke($this->service, 'TestRelatedObjects', $invalidHasMany, $relationInfo);
        $this->assertNotEmpty($errors, 'Invalid has_many structure with associative array should fail validation');

        // Test has_many validation with invalid structure (not an array)
        $invalidHasMany2 = 'Not an array';
        $errors = $method->invoke($this->service, 'TestRelatedObjects', $invalidHasMany2, $relationInfo);
        $this->assertNotEmpty($errors, 'Invalid has_many structure that is not an array should fail validation');
    }

    /**
     * Test elemental blocks validation with relationship fields
     */
    public function testValidateElementalBlocks()
    {
        // Make the private method accessible
        $method = new ReflectionMethod(ContentGeneratorService::class, 'validateElementalBlocks');
        $method->setAccessible(true);

        // Create element field definitions including relationship fields
        $elementBlockFields = [
            'ElementContent' => [
                'Title' => ['name' => 'Title', 'type' => 'Varchar'],
                'Content' => ['name' => 'Content', 'type' => 'HTMLText'],
                'RelatedObject' => ['name' => 'RelatedObject', 'type' => 'has_one', 'relationClass' => 'TestRelatedObject'],
                'RelatedItems' => ['name' => 'RelatedItems', 'type' => 'has_many', 'relationClass' => 'TestRelatedItem']
            ]
        ];

        // Test with valid block structure
        $validBlocks = [
            [
                'BlockType' => 'ElementContent',
                'Title' => 'Test Element',
                'Content' => '<p>Test content</p>',
                'RelatedObject' => [
                    'Title' => 'Related Object',
                    'Description' => 'Related description'
                ],
                'RelatedItems' => [
                    [
                        'Title' => 'Item 1',
                        'Description' => 'Item 1 description'
                    ],
                    [
                        'Title' => 'Item 2',
                        'Description' => 'Item 2 description'
                    ]
                ]
            ]
        ];

        $errors = $method->invoke($this->service, $validBlocks, $elementBlockFields);
        $this->assertEmpty($errors, 'Valid elemental block with relationships should pass validation');

        // Test with invalid relationship field in block (string instead of array)
        $invalidBlocks = [
            [
                'BlockType' => 'ElementContent',
                'Title' => 'Test Element',
                'Content' => '<p>Test content</p>',
                'RelatedObject' => 'Not an object',
                'RelatedItems' => 'Not an array'
            ]
        ];

        $errors = $method->invoke($this->service, $invalidBlocks, $elementBlockFields);
        $this->assertNotEmpty($errors, 'Invalid relationship fields in block should fail validation');
    }
}
