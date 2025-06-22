<?php

namespace KhalsaJio\ContentCreator\Services;

use Exception;
use SilverStripe\Forms\GridField\GridField;
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
     * Whether to show the page structure in the modal
     *
     * @config
     * @var bool
     */
    private static $show_page_structure = true;

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
        'Parent',
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
     * Max depth for recursive field structure generation
     */
    private static $max_recursion_depth = 6;

        /**
     * Track classes that have been processed at each depth to prevent infinite recursion
     * @var array
     */
    private $processedClasses = [];

    /**
     * Track the full path of class processing to detect circular references
     * @var array
     */
    private $classPath = [];

    /**
     * Check if the page structure should be shown in the modal
     *
     * @return bool
     */
    public function shouldShowPageStructure(): bool
    {
        return (bool)Config::inst()->get(ContentStructureService::class, 'show_page_structure');
    }

    /**
     * Get the field structure for a given DataObject, using cache
     *
     * @param DataObject $dataObject
     * @param bool $refreshCache Whether to refresh the cache, defaults to false
     * @return array The field structure
     * @throws Exception If there's an error generating the field structure
     */
    public function getPageFieldStructure(DataObject $dataObject, bool $refreshCache = true): array
    {
        $cacheKey = $this->cacheService->generateCacheKey($dataObject);

        if ($refreshCache) {
            $this->cacheService->delete($cacheKey);
        }

        // Reset processed classes and path to prevent issues with previous requests
        $this->processedClasses = [];
        $this->classPath = [];

        return $this->cacheService->getOrCreate(
            $cacheKey,
            function () use ($dataObject) {
                return $this->getObjectFieldStructure($dataObject, true, 0, 'page-root');
            }
        );
    }

    /**
     * Get all element types that are allowed for a given area or globally
     *
     * @param DataObject $page The page to check against
     * @param string|null $areaName The name of the elemental area
     * @param int $depth Current recursion depth
     * @return array
     */
    protected function getAllowedElementTypes(DataObject $page = null, string $areaName = null, int $depth = 0): array
    {
        // Prevent deep recursion
        if ($depth >= $this->config()->get('max_recursion_depth')) {
            return [];
        }

        // Create a specific path key that includes area name to avoid cycle detection conflicts
        // when processing multiple ElementalAreas in the same block
        $specificPathKey = $page ? ($depth . '-' . get_class($page) . '-' . $areaName) : null;
        if ($specificPathKey && in_array($specificPathKey, $this->classPath)) {
            // Skip if we've already processed this exact area to prevent cycles
            return [];
        }

        // Add area-specific path key if we have one
        if ($specificPathKey) {
            $this->classPath[] = $specificPathKey;
            $pathIndex = array_key_last($this->classPath);
        }

        $allowedTypes = [];

        if ($page && $areaName) {
            // First check if there's a config-level restriction for elements in this block
            if ($page->hasExtension(ElementalAreasExtension::class)) {
                $allowedClasses = $page->config()->get('allowed_elements');
                if (!empty($allowedClasses)) {
                    foreach ($allowedClasses as $className) {
                        $singleton = singleton($className);
                        if ($singleton->canCreate()) {
                            // For blocks, make sure we're getting a clean field structure without cycle detection interference
                            // by using a temporary clean path context
                            $originalClassPath = $this->classPath;
                            $this->classPath = [];

                            // Include the area name in the context to avoid cycle detection issues
                            // with multiple areas in the same block
                            $fields = $this->getObjectFieldStructure($className, true, $depth + 1, "allowed-{$areaName}-{$className}");

                            // Restore the original path context
                            $this->classPath = $originalClassPath;

                            $allowedTypes[] = [
                                'class' => $className,
                                'title' => $singleton->getType(),
                                'fields' => $fields
                            ];
                        }
                    }

                    if (!empty($allowedTypes)) {
                        return $allowedTypes;
                    }
                }
            }

            // If no config-level restrictions or no allowed elements found, try to find a GridField
            $gridField = null;
            $scaffoldFields = $page->getCMSFields();

            // Try to find the GridField for this elemental area
            // First try with dataFieldByName which handles nested fields better
            $field = $scaffoldFields->dataFieldByName($areaName);
            if ($field instanceof GridField) {
                $gridField = $field;
            }

            // If that didn't work, try with fieldByName which might help in some cases
            if (!$gridField) {
                $field = $scaffoldFields->fieldByName($areaName);
                if ($field instanceof GridField) {
                    $gridField = $field;
                }
            }

            // For blocks with ElementalAreasExtension, look through all tabs
            if (!$gridField && $page->hasExtension(ElementalAreasExtension::class)) {
                foreach ($scaffoldFields->fieldByName('Root')->Tabs() as $tab) {
                    $field = $tab->Fields()->dataFieldByName($areaName);
                    if ($field instanceof GridField) {
                        $gridField = $field;
                        break;
                    }
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
                            'fields' => $this->getObjectFieldStructure($normalizedClassName, true, $depth + 1)
                        ];
                    }

                    if (!empty($allowedTypes)) {
                        return $allowedTypes;
                    }
                }
            }
        }

        // Clean up path key before returning
        if (isset($pathIndex)) {
            unset($this->classPath[$pathIndex]);
        }

        return $this->getAllElementTypes($page, $depth + 1);
    }

    /**
     * Get all available element types, filtered by page restrictions if applicable
     *
     * @param DataObject $page The page to check restrictions against
     * @param int $depth Current recursion depth
     * @return array
     */
    protected function getAllElementTypes(DataObject $page = null, int $depth = 0): array
    {
        // Prevent deep recursion
        if ($depth >= $this->config()->get('max_recursion_depth')) {
            return [];
        }

        $elementTypes = [];

        // Only proceed if Elemental is installed
        if (!class_exists(BaseElement::class)) {
            return $elementTypes;
        }

        // First check if the page/block has specific allowed_elements in its config
        if ($page) {
            $allowedElements = $page->config()->get('allowed_elements');
            if (!empty($allowedElements)) {
                foreach ($allowedElements as $className) {
                    if (class_exists($className)) {
                        $singleton = singleton($className);
                        if ($singleton->canCreate()) {
                            $elementTypes[] = [
                                'class' => $className,
                                'title' => $singleton->getType(),
                                'fields' => $this->getObjectFieldStructure($className, true, $depth + 1, "gridfield-{$className}")
                            ];
                        }
                    }
                }

                if (!empty($elementTypes)) {
                    return $elementTypes;
                }
            }
        }

        // If a page is provided and has the ElementalAreasExtension, use its method
        if ($page && $page->hasExtension(ElementalAreasExtension::class)) {
            $elementalTypes = $page->getElementalTypes();

            foreach ($elementalTypes as $className => $title) {
                // Use a clean path context for each element type
                $originalClassPath = $this->classPath;
                $this->classPath = [];

                $fields = $this->getObjectFieldStructure($className, true, $depth + 1, "elemental-type-{$className}");

                // Restore path context
                $this->classPath = $originalClassPath;

                $elementTypes[] = [
                    'class' => $className,
                    'title' => $title,
                    'fields' => $fields
                ];
            }

            if (!empty($elementTypes)) {
                return $elementTypes;
            }
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
                $fields = $this->getObjectFieldStructure($className, true, $depth + 1, "element-base-{$className}");

                $elementTypes[] = [
                    'class' => $className,
                    'title' => $singleton->getType(),
                    'fields' => $fields
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
     * @param string $fieldContext Optional field/relation name context for cycle detection
     * @return array The field structure
     */
    public function getObjectFieldStructure($objectOrClass, bool $includeElementalAreas = true, int $depth = 0, string $fieldContext = ''): array
    {
        // Prevent infinite recursion by checking depth
        if ($depth > $this->config()->get('max_recursion_depth')) {
            return []; // Stop at a max depth
        }

        // Get class name for cycle detection
        $className = is_string($objectOrClass) ? $objectOrClass : get_class($objectOrClass);

        // Prevent recursion cycles by tracking classes we've already seen at each depth
        if (!isset($this->processedClasses[$depth])) {
            $this->processedClasses[$depth] = [];
        }

        // Include both depth and field context in the path key to allow the same class
        // to be processed at different depths and in different contexts (e.g., multiple ElementalAreas)
        $pathKey = $depth . '-' . $className . ($fieldContext ? "-{$fieldContext}" : '');

        if (in_array($pathKey, $this->classPath)) {
            // We have a circular reference in this specific context
            return [];
        }

        // Only check class-level recursion if we don't have a specific field context
        if (empty($fieldContext) && in_array($className, $this->processedClasses[$depth])) {
            // We've already processed this class at this depth with no specific context, avoid recursion cycle
            return [];
        }

        // Add this class to the processed list at this depth and to the path
        if (empty($fieldContext)) {
            $this->processedClasses[$depth][] = $className;
        }
        $this->classPath[] = $pathKey;

        // We'll remove this class from the path when we're done with it
        $pathIndex = array_key_last($this->classPath);

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
                $fieldName = $field->getName();

                $fieldData = [
                    'name' => $fieldName,
                    'title' => $field->Title() ?: $fieldName,
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
        $hasOneRelations = $object->config()->get('has_one') ?: [];
        if (!empty($hasOneRelations)) {
            $excludedFieldNames = $this->config()->get('excluded_field_names');
            $this->extend('updateExcludedFieldNames', $excludedFieldNames);

            foreach ($hasOneRelations as $relationName => $relationClass) {
                // Skip explicitly excluded fields
                if (in_array($relationName, $excludedFieldNames)) {
                    continue;
                }

                // Always include ElementalArea relations
                $isElementalArea = is_a($relationClass, ElementalArea::class, true);

                if ($isElementalArea) {
                    // Skip ElementalArea relations - these are handled later
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
                        'fields' => $this->getObjectFieldStructure($relationClass, true, $depth + 1, "has_one-{$relationName}")
                    ];
                }
            }
        }

        // Add has_many relationship fields
        $hasManyRelations = $object->config()->get('has_many') ?: [];
        if (!empty($hasManyRelations)) {
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
                    'fields' => $this->getObjectFieldStructure($relationClass, true, $depth + 1, "has_many-{$relationName}")
                ];
            }
        }

        // Add many_many relationship fields
        $manyManyRelations = $object->config()->get('many_many') ?: [];
        if (!empty($manyManyRelations)) {
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
                    'fields' => $this->getObjectFieldStructure($relationClass, true, $depth + 1, "many_many-{$relationName}")
                ];
            }
        }

        // Check for belongs_many_many relationship fields
        $belongsManyManyRelations = $object->config()->get('belongs_many_many') ?: [];
        if (!empty($belongsManyManyRelations)) {
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
                    'fields' => $this->getObjectFieldStructure($relationClass, false, $depth + 1, "belongs_many_many-{$relationName}")
                ];
            }
        }

        // Only include ElementalAreas if we should and we haven't reached the max recursion depth
        if ($includeElementalAreas && $depth <= $this->config()->get('max_recursion_depth')) {
            // Get all ElementalAreas for this object
            $elementalAreas = $this->getElementalAreas($object);

            foreach ($elementalAreas as $areaName => $areaInfo) {
                // Check the depth before adding allowed element types to prevent deep recursion
                $allowedElementTypes = [];
                if ($depth < $this->config()->get('max_recursion_depth')) {
                    // Get allowed element types for this area
                    $allowedElementTypes = $this->getAllowedElementTypes($object, $areaName, $depth + 1);

                    // If no specific element types are found and this is a block with ElementalAreasExtension,
                    // fallback to the config-level allowed_elements
                    if (empty($allowedElementTypes) && $object->hasExtension(ElementalAreasExtension::class)) {
                        $allowedElements = $object->config()->get('allowed_elements');
                        if (!empty($allowedElements)) {
                            foreach ($allowedElements as $className) {
                                if (class_exists($className)) {
                                    $singleton = singleton($className);
                                    if ($singleton->canCreate()) {
                                        // Use a clean path context to avoid interference from cycle detection
                                        $originalClassPath = $this->classPath;
                                        $this->classPath = [];

                                        // Include area name and class name in the context to avoid cycle detection conflicts
                                        $fields = $this->getObjectFieldStructure($className, true, $depth + 1, "allowed-{$areaName}-{$className}");

                                        // Restore path context
                                        $this->classPath = $originalClassPath;

                                        $allowedElementTypes[] = [
                                            'class' => $className,
                                            'title' => $singleton->getType(),
                                            'fields' => $fields
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }

                $fields[] = [
                    'name' => $areaName,
                    'title' => $areaInfo['title'],
                    'type' => 'ElementalArea',
                    'description' => 'Content blocks area',
                    'allowedElementTypes' => $allowedElementTypes
                ];
            }
        }

        // Remove this class from the path when we're done with it
        if (isset($pathIndex)) {
            unset($this->classPath[$pathIndex]);
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

        // Only include ElementalArea relationships if they're not the Parent relationship
        // This prevents the Parent/ParentID fields from appearing in blocks
        if (is_a($relationClass, ElementalArea::class, true)) {
            // Exclude the Parent relationship which is a default in BaseElement
            if ($relationName === 'Parent') {
                return false;
            }
            return true;
        }

        // Get configuration
        $includedClasses = $this->config()->get('included_relationship_classes') ?: [];
        $includedSpecificRelations = $this->config()->get('included_specific_relations') ?: [];
        $excludedSystemClasses = $this->config()->get('default_excluded_system_classes') ?: [];

        // Allow extension to update these configurations
        $this->extend(
            'updateShouldIncludeRelationship',
            $includedClasses,
            $includedSpecificRelations,
            $excludedSystemClasses
        );

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
        $labels = $this->config()->get('relationship_labels') ?: [];
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
     * Get the display label for a relationship type
     *
     * @param string $relationType The type of relation (has_one, has_many, etc.)
     * @return string The human-readable label
     */
    protected function getRelationshipLabel(string $relationType): string
    {
        $labels = $this->config()->get('relationship_labels') ?: [];
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
        // Ensure $excludedFieldNames is always an array to prevent in_array() errors
        if (!is_array($excludedFieldNames)) {
            $excludedFieldNames = [];
        }

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
     * Get the elemental areas for a page or any object
     *
     * @param DataObject $object Any DataObject that might have ElementalArea relations
     * @return array
     */
    protected function getElementalAreas(DataObject $object): array
    {
        $areas = [];
        $hasOne = $object->config()->get('has_one') ?: [];

        if (empty($hasOne)) {
            return $areas;
        }

        // Get excluded field names
        $excludedFieldNames = $this->config()->get('excluded_field_names');
        $this->extend('updateExcludedFieldNames', $excludedFieldNames);

        foreach ($hasOne as $relationName => $relationClass) {
            if (in_array($relationName, $excludedFieldNames)) {
                continue;
            }

            if (is_a($relationClass, ElementalArea::class, true)) {
                // Get the relation using the getter method
                $area = $object->$relationName();

                if ($area && $area->exists()) {
                    $areas[$relationName] = [
                        'title' => $this->formatFieldTitle($relationName),
                        'class' => $relationClass,
                    ];
                } else {
                    // If area doesn't exist but it is defined as ElementalArea, still include it
                    // This is needed for newly created objects that don't have areas written yet
                    $areas[$relationName] = [
                        'title' => $this->formatFieldTitle($relationName),
                        'class' => $relationClass,
                    ];
                }
            }
        }

        return $areas;
    }
}
