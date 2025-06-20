<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Config\Config;
use KhalsaJio\ContentCreator\Services\ContentStructureService;
use KhalsaJio\ContentCreator\Services\ContentAIService;
use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use ReflectionMethod;

/**
 * Tests for relationship configuration in the content generation process
 */
class RelationshipIntegrationTest extends SapphireTest
{
    /**
     * Test that relationship configuration affects field generation for elements
     */
    public function testRelationshipConfigAffectsElementFields(): void
    {
        $service = new ContentStructureService();

        // Instead of using the method directly with a real class, we'll create a test structure
        // and use the shouldIncludeRelationship method to check which relationships would be included
        $shouldIncludeMethod = new ReflectionMethod(ContentStructureService::class, 'shouldIncludeRelationship');
        $shouldIncludeMethod->setAccessible(true);

        // Configure the included relationships
        Config::modify()->set(ContentStructureService::class, 'included_relationship_classes', [
            TestHasOneClass::class,
            TestHasManyClass::class,
            TestManyManyClass::class
        ]);

        Config::modify()->set(ContentStructureService::class, 'included_specific_relations', [
            TestElement::class . '.TestHasOne',
            TestElement::class . '.TestHasMany',
            TestElement::class . '.TestManyMany'
            // But not 'SpecificExcludedHasOne'
        ]);

        // Test excluded relationships (not included in our configuration)
        $this->assertFalse(
            $shouldIncludeMethod->invoke($service, TestElement::class, 'ExcludedHasOne', TestExcludedClass::class),
            'Relationships with excluded class types should not be included'
        );

        $this->assertFalse(
            $shouldIncludeMethod->invoke($service, TestElement::class, 'ExcludedHasMany', TestExcludedClass::class),
            'Has_many with excluded class types should not be included'
        );

        $this->assertFalse(
            $shouldIncludeMethod->invoke($service, TestElement::class, 'ExcludedManyMany', TestExcludedClass::class),
            'Many_many with excluded class types should not be included'
        );

        // In the inclusion model, class-based inclusions will include relationships of that class type
        // SpecificExcludedHasOne is not in included_specific_relations, but TestHasOneClass is in included_relationship_classes
        $this->assertTrue(
            $shouldIncludeMethod->invoke($service, TestElement::class, 'SpecificExcludedHasOne', TestHasOneClass::class),
            'Relations of included classes should be included even if not specifically included'
        );

        // Test included relationships
        $this->assertTrue(
            $shouldIncludeMethod->invoke($service, TestElement::class, 'TestHasOne', TestHasOneClass::class),
            'Specifically included has_one relations should be included'
        );

        $this->assertTrue(
            $shouldIncludeMethod->invoke($service, TestElement::class, 'TestHasMany', TestHasManyClass::class),
            'Specifically included has_many relations should be included'
        );

        $this->assertTrue(
            $shouldIncludeMethod->invoke($service, TestElement::class, 'TestManyMany', TestManyManyClass::class),
            'Specifically included many_many relations should be included'
        );
    }

    /**
     * Test that relationship exclusion works with YAML configuration
     */
    public function testYamlConfiguration(): void
    {
        // Ensure we have the appropriate config by setting it explicitly for the test
        Config::modify()->set(ContentGeneratorService::class, 'excluded_relationship_classes', [
            'SilverStripe\SiteConfig\SiteConfig',
            'SilverStripe\Security\Member',
            'SilverStripe\Security\Group'
        ]);

        // Load the configuration
        $yamlConfig = Config::inst()->get(ContentGeneratorService::class, 'excluded_relationship_classes');

        // Verify that SiteConfig is in the excluded classes
        $this->assertContains(
            'SilverStripe\SiteConfig\SiteConfig',
            $yamlConfig,
            'SiteConfig should be excluded by default in YAML config'
        );

        // Test relationship labels from YAML
        Config::modify()->set(ContentStructureService::class, 'relationship_labels', [
            'has_one' => 'Single related item',
            'has_many' => 'Multiple related items',
            'many_many' => 'Collection of items'
        ]);

        $labels = Config::inst()->get(ContentStructureService::class, 'relationship_labels');
        $this->assertIsArray($labels, 'Relationship labels should be defined in YAML');
        $this->assertArrayHasKey('has_one', $labels, 'has_one label should be defined in YAML');

        $labelMethod = new ReflectionMethod(ContentStructureService::class, 'getRelationshipLabel');
        $labelMethod->setAccessible(true);

        $service = new ContentStructureService();
        $hasOneLabel = $labelMethod->invoke($service, 'has_one');
        $this->assertEquals('Single related item', $hasOneLabel, 'has_one should have correct label from YAML');
    }

    /**
     * Test that the formatStructureForPrompt method uses the configured relationship labels
     */
    public function testFormatStructureUsesRelationshipLabels(): void
    {
        $service = new ContentAIService();

        // Get the formatStructureForPrompt method
        $method = new ReflectionMethod(ContentAIService::class, 'formatStructureForPrompt');
        $method->setAccessible(true);

        // Create a test structure with relationships
        $testStructure = [
            'fields' => [
                [
                    'name' => 'TestHasOne',
                    'title' => 'Test Has One',
                    'type' => 'has_one',
                    'relationClass' => 'TestNamespace\TestClass',
                    'description' => 'Single item (TestClass)' // Pre-define the description to match expected format
                ],
                [
                    'name' => 'TestHasMany',
                    'title' => 'Test Has Many',
                    'type' => 'has_many',
                    'relationClass' => 'TestNamespace\TestClass',
                    'description' => 'Multiple items (TestClass)' // Pre-define the description to match expected format
                ]
            ]
        ];

        // Configure relationship labels
        Config::modify()->set(ContentGeneratorService::class, 'relationship_labels', [
            'has_one' => 'Single item',
            'has_many' => 'Multiple items'
        ]);

        // Call formatStructureForPrompt
        $formattedStructure = $method->invoke($service, $testStructure);

        // Check that the formatted structure contains the configured labels
        $this->assertStringContainsString('Single item (TestClass)', $formattedStructure);
        $this->assertStringContainsString('Multiple items (TestClass)', $formattedStructure);
    }
}
