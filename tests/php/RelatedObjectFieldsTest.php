<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use ReflectionMethod;

/**
 * Tests for related object fields configuration
 */
class RelatedObjectFieldsTest extends SapphireTest
{
    /**
     * List of required fixtures
     *
     * @var array
     */
    protected static $fixture_file = [
        "./fixtures/RelationshipConfigurationTestFixture.yml"
    ];

    /**
     * List of extra required DataObjects
     */
    protected static $extra_dataobjects = [
        TestElement::class,
        TestHasOneClass::class,
        TestHasManyClass::class,
        TestManyManyClass::class,
        TestManyManyJoin::class,
        TestExcludedClass::class
    ];

    /**
     * Test that related object fields are properly included in getRelatedObjectFields
     */
    public function testRelatedObjectFields(): void
    {
        $service = new ContentGeneratorService();

        $method = new ReflectionMethod(ContentGeneratorService::class, "getRelatedObjectFields");
        $method->setAccessible(true);

        // Configure with specific fields
        Config::modify()->set(ContentGeneratorService::class, "related_object_fields", [
            TestHasOneClass::class => ["Title", "Content"],
            TestHasManyClass::class => ["Name", "Description"]
        ]);

        // Test direct class references
        $fields1 = $method->invoke($service, TestHasOneClass::class);
        $this->assertEquals(["Title", "Content"], $fields1);

        $fields2 = $method->invoke($service, TestHasManyClass::class);
        $this->assertEquals(["Name", "Description"], $fields2);

        // Test unconfigured class
        $fields3 = $method->invoke($service, TestExcludedClass::class);
        $this->assertNull($fields3, "Should return null for unconfigured classes");

        // Test array relation class (many_many)
        $arrayRelation = [
            "through" => TestManyManyJoin::class,
            "to" => TestHasOneClass::class
        ];

        $fields4 = $method->invoke($service, $arrayRelation);
        $this->assertEquals(["Title", "Content"], $fields4, "Should extract target class from array relation");
    }

    /**
     * Test the integration of related object fields into formatStructureForPrompt
     */
    public function testRelatedObjectFieldsInStructure(): void
    {
        $service = new ContentGeneratorService();

        // Access the protected methods we need to test
        $elementFieldsMethod = new ReflectionMethod(ContentGeneratorService::class, "getElementFields");
        $elementFieldsMethod->setAccessible(true);

        $formatMethod = new ReflectionMethod(ContentGeneratorService::class, "formatStructureForPrompt");
        $formatMethod->setAccessible(true);

        // Configure with specific fields
        Config::modify()->set(ContentGeneratorService::class, "related_object_fields", [
            TestHasOneClass::class => ["Title"],
            TestHasManyClass::class => ["Name"],
            TestManyManyClass::class => ["Code"]
        ]);

        // Configure to include these relationship classes
        Config::modify()->set(ContentGeneratorService::class, "included_relationship_classes", [
            TestHasOneClass::class,
            TestHasManyClass::class,
            TestManyManyClass::class
        ]);

        // Also add specific inclusions for the test element class relations
        Config::modify()->set(ContentGeneratorService::class, "included_specific_relations", [
            TestElement::class . ".TestHasOne",
            TestElement::class . ".TestHasMany",
            TestElement::class . ".TestManyMany"
        ]);

        // Create a structure with the relationships manually
        $structure = [
            "className" => TestElement::class,
            "fields" => [
                [
                    "name" => "Title",
                    "title" => "Title",
                    "type" => "SilverStripe\Forms\TextField",
                    "description" => ""
                ],
                [
                    "name" => "TestHasOne",
                    "title" => "Test Has One",
                    "type" => "has_one",
                    "description" => "Single item relationship (TestHasOneClass)"
                ],
                [
                    "name" => "TestHasOneID",
                    "title" => "Test Has One",
                    "type" => "SilverStripe\Forms\SearchableDropdownField",
                    "description" => ""
                ],
                [
                    "name" => "TestHasMany",
                    "title" => "Test Has Many",
                    "type" => "has_many",
                    "description" => "Multiple items collection (TestHasManyClass)"
                ],
                [
                    "name" => "TestManyMany",
                    "title" => "Test Many Many",
                    "type" => "many_many",
                    "description" => "Many-to-many collection (TestManyManyClass)"
                ]
            ]
        ];

        // Format the structure
        $formatted = $formatMethod->invoke($service, $structure);

        // Verify relationship fields are properly formatted
        $this->assertStringContainsString("TestHasOne", $formatted);
        $this->assertStringContainsString("TestHasOneID", $formatted, "Should include has_one field ID");

        // Check that relationship types are included
        $this->assertStringContainsString("type: has_one", $formatted, "Should include has_one relationship type");

        // TestHasMany should be included since we configured it in included_relationship_classes
        $this->assertStringContainsString("TestHasMany", $formatted, "TestHasMany should be included in the structure");

        // TestManyMany should be included since we configured it in included_relationship_classes
        $this->assertStringContainsString("TestManyMany", $formatted, "TestManyMany should be included in the structure");
    }
}
