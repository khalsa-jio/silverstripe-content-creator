<?php

namespace KhalsaJio\ContentCreator\Services;

use Exception;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementalArea;

/**
 * Service responsible for populating DataObjects with generated content
 */
class ContentPopulatorService extends BaseContentService
{
    /**
     * Populate a DataObject with generated content
     *
     * @param DataObject $dataObject The object to populate
     * @param array $generatedContent The content generated from AI in structured format
     * @param bool $write Whether to write the object after population
     * @return DataObject The populated object
     * @throws Exception
     */
    public function populateContent(DataObject $dataObject, array $generatedContent, bool $write = true): DataObject
    {
        try {
            // Start transaction to ensure either all fields are populated or none
            DB::get_conn()->transactionStart();

            foreach ($generatedContent as $fieldName => $fieldValue) {
                $this->populateField($dataObject, $fieldName, $fieldValue);
            }

            if ($write) {
                $dataObject->write();
            }

            // Commit transaction
            DB::get_conn()->transactionEnd();

            $this->logger->info("Successfully populated content for {$dataObject->ClassName} ID: {$dataObject->ID}");

            return $dataObject;
        } catch (Exception $e) {
            // Rollback transaction on error
            DB::get_conn()->transactionRollback();
            $this->logger->error("Failed to populate content: " . $e->getMessage(), [
                'object_class' => $dataObject->ClassName,
                'object_id' => $dataObject->ID,
                'content' => $generatedContent
            ]);
            throw $e;
        }
    }

    /**
     * Populate a specific field on a DataObject
     *
     * @param DataObject $dataObject
     * @param string $fieldName
     * @param mixed $fieldValue
     * @throws Exception
     */
    protected function populateField(DataObject $dataObject, string $fieldName, $fieldValue): void
    {
        // Skip if field value is null or empty
        if ($fieldValue === null || $fieldValue === '') {
            return;
        }

        // Check for has_one relationships
        $hasOneRelations = $dataObject->config()->get('has_one');
        if ($hasOneRelations && isset($hasOneRelations[$fieldName])) {
            $this->populateHasOneRelation($dataObject, $fieldName, $fieldValue);
            return;
        }

        // Check for has_many/many_many relationships
        $manyRelations = array_merge(
            $dataObject->config()->get('has_many') ?: [],
            $dataObject->config()->get('many_many') ?: [],
            $dataObject->config()->get('belongs_many_many') ?: []
        );

        if (!empty($manyRelations) && isset($manyRelations[$fieldName])) {
            $this->populateManyRelation($dataObject, $fieldName, $fieldValue, $manyRelations[$fieldName]);
            return;
        }

        // Check for elemental areas
        if ($this->isElementalArea($dataObject, $fieldName)) {
            $this->populateElementalArea($dataObject, $fieldName, $fieldValue);
            return;
        }

        // If we're here, it's a direct field value
        $dataObject->$fieldName = $fieldValue;
    }

    /**
     * Populate a has_one relationship
     *
     * @param DataObject $dataObject
     * @param string $relationName
     * @param mixed $value
     * @throws Exception
     */
    protected function populateHasOneRelation(DataObject $dataObject, string $relationName, $value): void
    {
        // Don't process ElementalArea relations or non-array values
        $hasOne = $dataObject->config()->get('has_one');
        if (!isset($hasOne[$relationName]) || !is_array($value)) {
            return;
        }

        $relationClass = $hasOne[$relationName];

        // Skip ElementalArea relations (they should be handled by populateElementalArea)
        if (is_a($relationClass, ElementalArea::class, true)) {
            return;
        }

        // Create a new object of the related class
        $relatedObject = $relationClass::create();

        // Populate its fields
        foreach ($value as $subFieldName => $subFieldValue) {
            $this->populateField($relatedObject, $subFieldName, $subFieldValue);
        }

        // Save the related object
        $relatedObject->write();

        // Link it to the parent object
        $relationField = $relationName . 'ID';
        $dataObject->$relationField = $relatedObject->ID;
    }

    /**
     * Populate a has_many or many_many relationship
     *
     * @param DataObject $dataObject
     * @param string $relationName
     * @param mixed $value
     * @param string $relationClass
     * @throws Exception
     */
    protected function populateManyRelation(DataObject $dataObject, string $relationName, $value, string $relationClass): void
    {
        if (!is_array($value)) {
            return;
        }

        // Create a list to track the related objects
        $relatedObjects = [];

        // Handle each item in the array
        foreach ($value as $itemData) {
            if (!is_array($itemData)) {
                continue;
            }

            // Create a new object of the related class
            $relatedObject = $relationClass::create();

            // Populate its fields
            foreach ($itemData as $subFieldName => $subFieldValue) {
                $this->populateField($relatedObject, $subFieldName, $subFieldValue);
            }

            // Save the related object
            $relatedObject->write();

            $relatedObjects[] = $relatedObject;
        }

        // Only continue if there are related objects to associate
        if (empty($relatedObjects)) {
            return;
        }

        // Write the parent object if needed for has_many
        if (!$dataObject->isInDB()) {
            $dataObject->write();
        }

        // Find the foreign key field for has_many
        $foreignKey = $this->findHasManyForeignKey($dataObject, $relationName);

        // Handle has_many relation using the foreign key
        if ($foreignKey) {
            foreach ($relatedObjects as $relatedObject) {
                $relatedObject->$foreignKey = $dataObject->ID;
                $relatedObject->write();
            }
            return;
        }

        // For many_many relations we need to add to the relation
        $relation = $dataObject->$relationName();
        if ($relation && method_exists($relation, 'add')) {
            foreach ($relatedObjects as $relatedObject) {
                $relation->add($relatedObject);
            }
        }
    }

    /**
     * Find the foreign key field name for a has_many relationship
     *
     * @param DataObject $parentObject
     * @param string $relationName
     * @return string|null
     */
    protected function findHasManyForeignKey(DataObject $parentObject, string $relationName): ?string
    {
        $hasManyConfig = $parentObject->config()->get('has_many');

        if (!isset($hasManyConfig[$relationName])) {
            return null;
        }

        $childClass = $hasManyConfig[$relationName];

        // Check if the relation definition includes the foreign key
        if (strpos($childClass, '.') !== false) {
            list($childClass, $foreignKey) = explode('.', $childClass);
            return $foreignKey;
        }

        // Try to find the foreign key by examining the child class's has_one relations
        $childObject = singleton($childClass);
        $childHasOne = $childObject->config()->get('has_one');

        if (!$childHasOne) {
            return null;
        }

        $parentClass = get_class($parentObject);

        foreach ($childHasOne as $relationName => $relationClass) {
            if ($relationClass === $parentClass || is_subclass_of($parentClass, $relationClass)) {
                return $relationName . 'ID';
            }
        }

        return null;
    }

    /**
     * Check if a field is an elemental area
     *
     * @param DataObject $dataObject
     * @param string $fieldName
     * @return bool
     */
    protected function isElementalArea(DataObject $dataObject, string $fieldName): bool
    {
        $hasOne = $dataObject->config()->get('has_one');

        if (!$hasOne || !isset($hasOne[$fieldName])) {
            return false;
        }

        return is_a($hasOne[$fieldName], ElementalArea::class, true);
    }

    /**
     * Populate an elemental area with blocks
     *
     * @param DataObject $dataObject
     * @param string $fieldName
     * @param array $blocksData
     * @throws Exception
     */
    protected function populateElementalArea(DataObject $dataObject, string $fieldName, array $blocksData): void
    {
        if (!is_array($blocksData)) {
            return;
        }

        // Ensure parent object is written first
        if (!$dataObject->isInDB()) {
            $dataObject->write();
        }

        // Get or create the elemental area
        $elementalArea = $dataObject->$fieldName();
        if (!$elementalArea || !$elementalArea->exists()) {
            $elementalArea = ElementalArea::create();
            $elementalArea->write();
            $dataObject->{$fieldName . 'ID'} = $elementalArea->ID;
            $dataObject->write();
        }

        $validBlockTypesFields = [
                'BlockType',
                'ClassName',
                'Type',
                'type',
            ];

        $validBlockTypes = array_merge(
            $validBlockTypesFields,
            $dataObject->config()->get('elemental_block_types') ?: []
        );


        $sort = 1;
        foreach ($blocksData as $blockData) {
            // Try multiple approaches to determine the block class
            $blockClass = null;

            // Check for known block type fields
            foreach ($validBlockTypes as $field) {
                if (isset($blockData[$field])) {
                    $blockClass = $this->unsanitiseClassName($blockData[$field]);
                    break;
                }
            }

            // If we still don't have a class, skip this block
            if (!$blockClass) {
                $this->logger->warning("Unable to determine element block class from data", [
                    'data' => $blockData
                ]);
                continue;
            }

            // Check if the class exists and is a valid element type
            if (!class_exists($blockClass) || !is_subclass_of($blockClass, BaseElement::class)) {
                $this->logger->warning("Block class '{$blockClass}' does not exist");
                continue;
            }

            // Create the block
            $block = $blockClass::create();
            $block->ParentID = $elementalArea->ID;
            $block->Sort = $sort++;

            // Populate block fields
            foreach ($blockData as $blockFieldName => $blockFieldValue) {
                if (in_array($blockFieldName, $validBlockTypesFields)) {
                    continue;
                }
                $this->populateField($block, $blockFieldName, $blockFieldValue);
            }

            $block->write();
        }
    }
}
