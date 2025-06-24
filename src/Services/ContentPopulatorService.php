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
     * @param bool $replaceRelations Whether to replace existing items in relations (has_many, elemental)
     * @return DataObject The populated object
     * @throws Exception
     */
    public function populateContent(
        DataObject $dataObject,
        array $generatedContent,
        bool $write = true,
        bool $replaceRelations = true
    ): DataObject {
        try {
            DB::get_conn()->transactionStart();

            foreach ($generatedContent as $fieldName => $fieldValue) {
                $this->populateField($dataObject, $fieldName, $fieldValue, $replaceRelations);
            }

            if ($write) {
                $dataObject->write();
            }

            DB::get_conn()->transactionEnd();
            $this->logger->info("Successfully populated content for {$dataObject->ClassName} ID: {$dataObject->ID}");

            return $dataObject;
        } catch (Exception $e) {
            DB::get_conn()->transactionRollback();
            $this->logger->error("Failed to populate content: " . $e->getMessage(), [
                'object_class' => $dataObject->ClassName,
                'object_id' => $dataObject->ID,
                'content' => $generatedContent,
                'trace' => $e->getTraceAsString(),
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
     * @param bool $replaceRelations
     * @throws Exception
     */
    protected function populateField(DataObject $dataObject, string $fieldName, $fieldValue, bool $replaceRelations): void
    {
        if ($fieldValue === null || $fieldValue === '') {
            return;
        }

        if (!$dataObject->hasField($fieldName) && !$dataObject->hasMethod($fieldName)) {
            $this->logger->debug("Skipping non-existent field '{$fieldName}' on {$dataObject->ClassName}.");
            return;
        }

        if ($this->isElementalArea($dataObject, $fieldName)) {
            $this->populateElementalArea($dataObject, $fieldName, $fieldValue, $replaceRelations);
            return;
        }

        $hasOneRelations = $dataObject->config()->get('has_one');
        if ($hasOneRelations && isset($hasOneRelations[$fieldName])) {
            $this->populateHasOneRelation($dataObject, $fieldName, $fieldValue, $replaceRelations);
            return;
        }

        $manyRelations = array_merge(
            $dataObject->config()->get('has_many') ?: [],
            $dataObject->config()->get('many_many') ?: [],
            $dataObject->config()->get('belongs_many_many') ?: []
        );

        if (!empty($manyRelations) && isset($manyRelations[$fieldName])) {
            $this->populateManyRelation($dataObject, $fieldName, $fieldValue, $manyRelations[$fieldName], $replaceRelations);
            return;
        }

        $dataObject->$fieldName = $fieldValue;
    }

    /**
     * Populate a has_one relationship
     *
     * @param DataObject $dataObject
     * @param string $relationName
     * @param mixed $value
     * @param bool $replaceRelations
     * @throws Exception
     */
    protected function populateHasOneRelation(DataObject $dataObject, string $relationName, $value, bool $replaceRelations): void
    {
        $hasOne = $dataObject->config()->get('has_one');
        if (!isset($hasOne[$relationName]) || !is_array($value)) {
            return;
        }

        $relationClass = $hasOne[$relationName];

        $relatedObject = $dataObject->$relationName();

        if (!$relatedObject || !$relatedObject->exists()) {
            $relatedObject = $relationClass::create();
        }

        foreach ($value as $subFieldName => $subFieldValue) {
            $this->populateField($relatedObject, $subFieldName, $subFieldValue, $replaceRelations);
        }

        $relatedObject->write();

        $relationField = $relationName . 'ID';
        if ($dataObject->$relationField !== $relatedObject->ID) {
            $dataObject->$relationField = $relatedObject->ID;
        }
    }

    /**
     * Populate a has_many or many_many relationship
     *
     * @param DataObject $dataObject
     * @param string $relationName
     * @param mixed $value
     * @param string $relationClass
     * @param bool $replaceRelations
     * @throws Exception
     */
    protected function populateManyRelation(DataObject $dataObject, string $relationName, $value, string $relationClass, bool $replaceRelations): void
    {
        if (!is_array($value)) {
            return;
        }

        if (!$dataObject->isInDB()) {
            $dataObject->write();
        }

        $relation = $dataObject->$relationName();

        if ($replaceRelations && $relation instanceof RelationList) {
            $relation->removeAll();
        }

        foreach ($value as $itemData) {
            if (!is_array($itemData)) {
                continue;
            }

            $relatedObject = $relationClass::create();
            foreach ($itemData as $subFieldName => $subFieldValue) {
                $this->populateField($relatedObject, $subFieldName, $subFieldValue, $replaceRelations);
            }
            $relatedObject->write();

            $relation->add($relatedObject);
        }
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

        $relationClass = $hasOne[$fieldName];

        // Check direct ElementalArea class or extensions
        if (is_a($relationClass, ElementalArea::class, true)) {
            return true;
        }

        // Check for fields with ElementalArea in name that might be nested areas
        if (strpos($fieldName, 'Elements') !== false && $dataObject->hasMethod($fieldName)) {
            $relation = $dataObject->$fieldName();
            return ($relation instanceof ElementalArea);
        }

        return false;
    }

    /**
     * Populate an elemental area with blocks
     *
     * @param DataObject $dataObject
     * @param string $fieldName
     * @param array $blocksData
     * @param bool $replaceRelations
     * @throws Exception
     */
    protected function populateElementalArea(DataObject $dataObject, string $fieldName, array $blocksData, bool $replaceRelations): void
    {
        if (!is_array($blocksData)) {
            return;
        }

        if (!$dataObject->isInDB()) {
            $dataObject->write();
        }

        $elementalArea = $dataObject->$fieldName();
        if (!$elementalArea || !$elementalArea->exists()) {
            $elementalArea = ElementalArea::create();
            $elementalArea->write();
            $dataObject->{$fieldName . 'ID'} = $elementalArea->ID;
            $dataObject->write();
        }

        if ($replaceRelations) {
            foreach ($elementalArea->Elements() as $existingElement) {
                if ($existingElement->hasMethod('doUnpublish')) {
                    $existingElement->doUnpublish();
                }
                $existingElement->delete();
            }
        }

        $blockClassFieldCandidates = ['BlockType', 'ClassName', 'Class', 'Type', 'type'];

        $this->extend('updateValidBlockTypesFields', $blockClassFieldCandidates);

        $normalizedBlocksData = $this->normalizeBlocksData($blocksData, $blockClassFieldCandidates);

        $sort = 1;
        foreach ($normalizedBlocksData as $blockData) {
            $blockClass = null;

            foreach ($blockClassFieldCandidates as $field) {
                if (isset($blockData[$field])) {
                    $blockClass = $this->unsanitiseClassName($blockData[$field]);
                    break;
                }
            }

            if (!$blockClass) {
                $this->logger->warning("Unable to determine element block class from data", ['data' => $blockData]);
                continue;
            }

            if (!class_exists($blockClass) || !is_subclass_of($blockClass, BaseElement::class)) {
                $this->logger->warning("Resolved class '{$blockClass}' is not a valid Element.", ['data' => $blockData]);
                continue;
            }

            $block = $blockClass::create();
            $block->ParentID = $elementalArea->ID;
            $block->Sort = $sort++;

            foreach ($blockData as $blockFieldName => $blockFieldValue) {
                if (in_array($blockFieldName, $blockClassFieldCandidates)) {
                    continue;
                }
                $this->populateField($block, $blockFieldName, $blockFieldValue, $replaceRelations);
            }

            $block->write();
        }
    }

    /**
     * Normalize blocks data to always work with an indexed array of block data
     * This handles both direct key-value pairs and nested arrays of blocks
     *
     * @param array $blocksData The raw blocks data from LLM
     * @return array Normalized array of block data
     */
    protected function normalizeBlocksData(array $blocksData, $blockClassFieldOptions): array
    {
        if (isset($blocksData[0]) && is_array($blocksData[0])) {
            return $blocksData;
        }

        // LLM responses can have blocks in various keys, so we need to check multiple possibilities
        // I encountered cases where blocks were nested under 'blocks', 'Elements'
        $nestedKeys = ['blocks', 'Elements', 'elements', 'Blocks'];

        $this->extend('updateNestedKeys', arguments: $nestedKeys);

        foreach ($nestedKeys as $nestedKey) {
            if (isset($blocksData[$nestedKey]) && is_array($blocksData[$nestedKey])) {
                if (isset($blocksData[$nestedKey][0])) {
                    return $blocksData[$nestedKey];
                } else {
                    $result = [];
                    foreach ($blocksData[$nestedKey] as $key => $data) {
                        if (is_array($data)) {
                            $result[] = $data;
                        }
                    }
                    if (!empty($result)) {
                        return $result;
                    }
                }
            }
        }

        $result = [];
        foreach ($blocksData as $key => $value) {
            if (is_array($value)) {
                $hasBlockTypeField = false;

                foreach ($blockClassFieldOptions as $field) {
                    if (isset($value[$field])) {
                        $hasBlockTypeField = true;
                        break;
                    }
                }

                if ($hasBlockTypeField) {
                    $result[] = $value;
                }
            }
        }

        return !empty($result) ? $result : [$blocksData];
    }
}
