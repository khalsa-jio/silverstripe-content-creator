<?php

namespace KhalsaJio\ContentCreator\Services;

use Exception;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\URLField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Core\Config\Config;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementalArea;
use DNADesign\Elemental\Extensions\ElementalPageExtension;
use DNADesign\Elemental\Extensions\ElementalAreasExtension;
use Symbiote\GridFieldExtensions\GridFieldAddNewMultiClass;

/**
 * Service responsible for analyzing DataObject structure, fields, and relationships
 */
class ContentStructureService extends BaseContentService
{
    /**
     * List of Core CMS field names to exclude from content generation
     *
     * @config
     * @var array
     */
    private static $excluded_field_names = [
        'ID', 'Created', 'LastEdited', 'ClassName', 'URLSegment',
        'ShowInMenus', 'ShowInSearch', 'ParentID', 'Version',
        'OwnerClassName', 'ElementID', 'CMSEditLink', 'ExtraClass',
        'InlineEditable', 'CanViewType', 'CanEditType', 'HasBrokenFile',
        'HasBrokenLink', 'ReportClass', 'ShareTokenSalt', 'Priority',
    ];

    /**
     * List of included relationship classes
     * Only these relationships will be included in content generation
     * If empty, all relationships are excluded by default
     *
     * @config
     * @var array
     */
    private static $included_relationship_classes = [];

    /**
     * List of specific relations to include (format: ClassName.RelationName)
     * These relations will always be included regardless of class-based inclusion,
     *
     * @config
     * @var array
     */
    private static $included_specific_relations = [];

    /**
     * User-friendly labels for relationship types
     *
     * @config
     * @var array
     */
    private static $relationship_labels = [
        'has_one' => 'Single related item',
        'has_many' => 'Multiple related items',
        'many_many' => 'Multiple related items',
        'belongs_many_many' => 'Referenced in multiple items'
    ];

    /**
     * Get the field structure for a given DataObject, using cache
     *
     * @param DataObject $dataObject
     * @param bool $refreshCache Whether to refresh the cache, defaults to false
     * @return array The field structure
     * @throws Exception If there's an error generating the field structure
     */
    public function getPageFieldStructure(DataObject $dataObject, bool $refreshCache = false): array
    {
        $cacheKey = $this->cacheService->generateCacheKey($dataObject);

        if ($refreshCache) {
            $this->cacheService->delete($cacheKey);
        }

        return $this->cacheService->getOrCreate(
            $cacheKey,
            function () use ($dataObject) {
                return $this->generateFieldStructure($dataObject);
            }
        );
    }

    /**
     * Generate the field structure for a DataObject
     *
     * @param DataObject $dataObject
     * @return array
     */
    protected function generateFieldStructure(DataObject $dataObject): array
    {
        $structure = $this->getObjectFieldStructure($dataObject);
        $elementalAreas = $this->getElementalAreas($dataObject);

        // Add elemental areas to structure if exists
        if (!empty($elementalAreas)) {
            foreach ($elementalAreas as $areaName => $areaInfo) {
                $structure[] = [
                    'name' => $areaName,
                    'title' => $areaInfo['title'],
                    'type' => 'ElementalArea',
                    'description' => 'Content blocks area',
                    'allowedElementTypes' => $this->getAllowedElementTypes($dataObject, $areaName, $areaInfo['area'])
                ];
            }
        }

        return $structure;
    }

    /**
     * Get all element types that are allowed for a given area or globally
     *
     * @param DataObject $page The page to check against
     * @param string|null $areaName The name of the elemental area
     * @param ElementalArea|null $area The ElementalArea object
     * @return array
     */
    protected function getAllowedElementTypes(
        DataObject $page = null,
        string $areaName = null,
        ElementalArea $area = null
    ): array {
        $allowedTypes = [];

        if ($page && $areaName) {
            $gridField = null;
            $scaffoldFields = $page->getCMSFields();

            // Try to find the GridField for this elemental area
            foreach ($scaffoldFields->dataFieldsByName() as $fieldName => $field) {
                if ($fieldName === $areaName && $field instanceof \SilverStripe\Forms\GridField\GridField) {
                    $gridField = $field;
                    break;
                }
            }

            if ($gridField) {
                $config = $gridField->getConfig();
                $addNewMultiClass = $config->getComponentByType(GridFieldAddNewMultiClass::class);

                if ($addNewMultiClass) {
                    $classes = $addNewMultiClass->getClasses($gridField);
                    foreach ($classes as $className => $title) {
                        $normalizedClassName = $this->unsanitiseClassName($className);
                        $allowedTypes[] = [
                            'class' => $normalizedClassName,
                            'title' => $title,
                            'fields' => $this->getObjectFieldStructure($normalizedClassName, false)
                        ];
                    }

                    if (!empty($allowedTypes)) {
                        return $allowedTypes;
                    }
                }
            }
        }

        return $this->getAllElementTypes($page);
    }

    /**
     * Get all available element types, filtered by page restrictions if applicable
     *
     * @param DataObject $page The page to check restrictions against
     * @return array
     */
    protected function getAllElementTypes(DataObject $page = null): array
    {
        $elementTypes = [];

        // Only proceed if Elemental is installed
        if (!class_exists(BaseElement::class)) {
            return $elementTypes;
        }

        // If a page is provided and has the ElementalAreasExtension, use its method
        if ($page && $page->hasExtension(ElementalAreasExtension::class)) {
            $elementalTypes = $page->getElementalTypes();

            foreach ($elementalTypes as $className => $title) {
                $elementTypes[] = [
                    'class' => $className,
                    'title' => $title,
                    'fields' => $this->getObjectFieldStructure($className, false)
                ];
            }

            return $elementTypes;
        }

        // Fallback for when no page is provided or it doesn't have the extension
        $classes = ClassInfo::subclassesFor(BaseElement::class);
        array_shift($classes); // Remove the base class

        foreach ($classes as $className) {
            // Skip abstract classes
            $reflector = new \ReflectionClass($className);
            if ($reflector->isAbstract()) {
                continue;
            }

            $singleton = singleton($className);
            if ($singleton->canCreate()) {
                $elementTypes[] = [
                    'class' => $className,
                    'title' => $singleton->getType(),
                    'fields' => $this->getObjectFieldStructure($className, false)
                ];
            }
        }

        return $elementTypes;
    }

    /**
     * Get the field structure for any DataObject or class
     *
     * @param mixed $objectOrClass DataObject instance or class name string
     * @param bool $includeElementalAreas Whether to include elemental areas in the structure
     * @param int $depth The current recursion depth
     * @return array The field structure
     */
    public function getObjectFieldStructure($objectOrClass, bool $includeElementalAreas = true, int $depth = 0): array
    {
        // Prevent infinite recursion
        if ($depth > 2) {
            return []; // Stop at a max depth
        }

        $fields = [];

        // Ensure we have a real object to work with
        if (is_string($objectOrClass)) {
            if (class_exists($objectOrClass)) {
                $object = singleton($objectOrClass);
            } else {
                return $fields; // Class doesn't exist
            }
        } elseif ($objectOrClass instanceof DataObject) {
            $object = $objectOrClass;
        } else {
            return $fields; // Invalid input
        }

        // Get CMS fields from the object
        $scaffoldFields = $object->getCMSFields();

        // Add regular content fields from CMS fields
        foreach ($scaffoldFields->dataFields() as $field) {
            if ($this->isContentField($field)) {
                $fieldData = [
                    'name' => $field->getName(),
                    'title' => $field->Title() ?: $field->getName(),
                    'type' => get_class($field),
                    'description' => $field->getDescription() ?: ''
                ];

                // Add field options if available
                $options = $this->getFieldOptions($field);
                if ($options !== null) {
                    $fieldData['options'] = $options;
                }

                $fields[] = $fieldData;
            }
        }

        // Get className for relationship checks
        $className = get_class($object);

        // Check if this object has DB fields that weren't captured by CMS fields
        // This is important for custom elements that might not expose all fields in getCMSFields
        $dbFields = $object->config()->get('db');
        if (is_array($dbFields) && !empty($dbFields)) {
            $excludedFieldNames = $this->config()->get('excluded_field_names');
            $this->extend('updateExcludedFieldNames', $excludedFieldNames);

            foreach ($dbFields as $dbFieldName => $dbFieldType) {
                $alreadyIncluded = false;
                foreach ($fields as $existingField) {
                    if ($existingField['name'] === $dbFieldName) {
                        $alreadyIncluded = true;
                        break;
                    }
                }

                if (!$alreadyIncluded && !in_array($dbFieldName, $excludedFieldNames)) {
                    $fields[] = [
                        'name' => $dbFieldName,
                        'title' => $this->formatFieldTitle($dbFieldName),
                        'type' => $dbFieldType,
                        'description' => ''
                    ];
                }
            }
        }

        // Add has_one relationship fields
        $hasOneRelations = $object->config()->get('has_one');
        if ($hasOneRelations) {
            foreach ($hasOneRelations as $relationName => $relationClass) {
                // Skip ElementalArea relations as they are handled separately
                if (is_a($relationClass, ElementalArea::class, true)) {
                    continue;
                }

                // Skip relationships that are not explicitly included
                if (!$this->shouldIncludeRelationship($className, $relationName, $relationClass)) {
                    continue;
                }

                // Check if field already exists from CMS fields
                $alreadyIncluded = false;
                foreach ($fields as $existingField) {
                    if ($existingField['name'] === $relationName) {
                        $alreadyIncluded = true;
                        break;
                    }
                }

                if (!$alreadyIncluded) {
                    $fields[] = [
                        'name' => $relationName,
                        'title' => $this->formatFieldTitle($relationName),
                        'type' => 'has_one',
                        'relationClass' => $relationClass,
                        'description' => $this->getRelationshipDescription('has_one', $relationClass),
                        'fields' => $this->getObjectFieldStructure($relationClass, false, $depth + 1)
                    ];
                }
            }
        }

        // Add has_many relationship fields
        $hasManyRelations = $object->config()->get('has_many');
        if ($hasManyRelations) {
            foreach ($hasManyRelations as $relationName => $relationClass) {
                // Skip relationships that are not explicitly included
                if (!$this->shouldIncludeRelationship($className, $relationName, $relationClass)) {
                    continue;
                }

                $fields[] = [
                    'name' => $relationName,
                    'title' => $this->formatFieldTitle($relationName),
                    'type' => 'has_many',
                    'relationClass' => $relationClass,
                    'description' => $this->getRelationshipDescription('has_many', $relationClass),
                    'fields' => $this->getObjectFieldStructure($relationClass, false, $depth + 1)
                ];
            }
        }

        // Add many_many relationship fields
        $manyManyRelations = $object->config()->get('many_many');
        if ($manyManyRelations) {
            foreach ($manyManyRelations as $relationName => $relationClass) {
                // Skip relationships that are not explicitly included
                if (!$this->shouldIncludeRelationship($className, $relationName, $relationClass)) {
                    continue;
                }

                $fields[] = [
                    'name' => $relationName,
                    'title' => $this->formatFieldTitle($relationName),
                    'type' => 'many_many',
                    'relationClass' => $relationClass,
                    'description' => $this->getRelationshipDescription('many_many', $relationClass),
                    'fields' => $this->getObjectFieldStructure($relationClass, false, $depth + 1)
                ];
            }
        }

        // Check for belongs_many_many relationship fields
        $belongsManyManyRelations = $object->config()->get('belongs_many_many');
        if ($belongsManyManyRelations) {
            foreach ($belongsManyManyRelations as $relationName => $relationClass) {
                // Skip relationships that are not explicitly included
                if (!$this->shouldIncludeRelationship($className, $relationName, $relationClass)) {
                    continue;
                }

                $fields[] = [
                    'name' => $relationName,
                    'title' => $this->formatFieldTitle($relationName),
                    'type' => 'belongs_many_many',
                    'relationClass' => $relationClass,
                    'description' => $this->getRelationshipDescription('belongs_many_many', $relationClass),
                    'fields' => $this->getObjectFieldStructure($relationClass, false, $depth + 1)
                ];
            }
        }

        return $fields;
    }

    /**
     * Check if a relationship should be included in the structure
     *
     * @param string $className The class that has the relationship
     * @param string $relationName The name of the relationship
     * @param string|array $relationClass The class of the related object
     * @return bool
     */
    protected function shouldIncludeRelationship(string $className, string $relationName, $relationClass): bool
    {
        // If relationClass is an array (e.g., for many_many with through), get the actual class
        if (is_array($relationClass) && isset($relationClass['through'])) {
            $relationClass = $relationClass['through'];
        }

        // Get configuration
        $includedClasses = $this->config()->get('included_relationship_classes') ?: [];
        $includedSpecificRelations = $this->config()->get('included_specific_relations') ?: [];
        $excludedSystemClasses = $this->config()->get('default_excluded_system_classes') ?: [];

        // Allow extensions to update these configurations
        $this->extend('updateIncludedRelationshipClasses', $includedClasses);
        $this->extend('updateIncludedSpecificRelations', $includedSpecificRelations);
        $this->extend('updateExcludedSystemClasses', $excludedSystemClasses);

        // First check for specific inclusion
        $specificRelationKey = "$className.$relationName";
        if (in_array($specificRelationKey, $includedSpecificRelations)) {
            return true;
        }

        // Check for class-based exclusion (system classes)
        foreach ($excludedSystemClasses as $excludedClass) {
            if (is_a($relationClass, $excludedClass, true)) {
                return false;
            }
        }

        // If included classes are specified, only include those
        if (!empty($includedClasses)) {
            foreach ($includedClasses as $includedClass) {
                if (is_a($relationClass, $includedClass, true) || $relationClass == $includedClass) {
                    return true;
                }
            }
            return false; // Not in the inclusion list
        }

        // Default exclusion if not explicitly included
        return false;
    }

    /**
     * Get a description for a relationship type
     *
     * @param string $relationType The type of relation (has_one, has_many, etc.)
     * @param string|array $relationClass The class name of the related object or relation config array
     * @return string
     */
    protected function getRelationshipDescription(string $relationType, $relationClass): string
    {
        $labels = $this->config()->get('relationship_labels');
        $baseDescription = $labels[$relationType] ?? 'Related item';

        // Handle the case where there are no labels configured
        if (empty($labels)) {
            return $relationType;
        }

        // Extract the actual class name from array format
        if (is_array($relationClass) && isset($relationClass['to'])) {
            $relationClass = $relationClass['to'];
        }

        // Get the short class name (without namespace)
        $shortClassName = $this->getShortClassName($relationClass);

        return "$baseDescription ($shortClassName)";
    }
    
    /**
     * Helper method to get short class name
     *
     * @param string $className
     * @return string
     */
    private function getShortClassName(string $className): string 
    {
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * Get the display label for a relationship type
     *
     * @param string $relationType The type of relation (has_one, has_many, etc.)
     * @return string The human-readable label
     */
    protected function getRelationshipLabel(string $relationType): string
    {
        $labels = $this->config()->get('relationship_labels');
        return $labels[$relationType] ?? $relationType;
    }

    /**
     * Check if a field should be treated as a content field
     *
     * @param FormField $field
     * @return bool
     */
    protected function isContentField(FormField $field): bool
    {
        // First check if the field name is in the excluded list
        $excludedFieldNames = $this->config()->get('excluded_field_names') ?: [];
        $this->extend('updateExcludedFieldNames', $excludedFieldNames);

        // If the field name is in the excluded list, it's not a content field
        // regardless of its type
        if (in_array($field->getName(), $excludedFieldNames)) {
            return false;
        }

        // Now check if the field is of a content field type
        $contentFieldTypes = [
            TextField::class,
            TextareaField::class,
            HTMLEditorField::class,
            DropdownField::class,
            OptionsetField::class,
            EmailField::class,
            URLField::class,
            DateField::class,
            DatetimeField::class,
            ListboxField::class,
            NumericField::class,
            CheckboxField::class,
        ];

        $this->extend('updateContentFieldTypes', $contentFieldTypes);

        // Check if the field is of a content field type
        foreach ($contentFieldTypes as $contentFieldType) {
            if ($field instanceof $contentFieldType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the options for a form field if it's a field with options
     *
     * @param FormField $field
     * @return array|null
     */
    protected function getFieldOptions(FormField $field): ?array
    {
        if ($field instanceof DropdownField || $field instanceof ListboxField || $field instanceof OptionsetField) {
            return $field->getSource();
        }

        if ($field instanceof CheckboxField) {
            return ['0' => 'No', '1' => 'Yes'];
        }

        return null;
    }

    /**
     * Get the elemental areas for a page
     *
     * @param DataObject $page
     * @return array
     */
    protected function getElementalAreas(DataObject $page): array
    {
        $areas = [];

        if (!$page->hasExtension(ElementalPageExtension::class)) {
            return $areas;
        }

        $hasOne = $page->config()->get('has_one');
        if (!$hasOne) {
            return $areas;
        }

        foreach ($hasOne as $relationName => $className) {
            if (is_a($className, ElementalArea::class, true)) {
                $area = $page->$relationName();
                if ($area && $area->exists()) {
                    $areas[$relationName] = [
                        'title' => $this->formatFieldTitle($relationName),
                        'area' => $area
                    ];
                }
            }
        }

        return $areas;
    }
}
