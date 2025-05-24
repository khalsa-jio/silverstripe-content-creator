<?php

namespace KhalsaJio\ContentCreator\Services;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use Exception;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementalArea;
use DNADesign\Elemental\Extensions\ElementalPageExtension;
use Symbiote\GridFieldExtensions\GridFieldAddNewMultiClass;

/**
 * Service for generating content based on page fields or Elemental blocks
 */
class ContentGeneratorService
{
    use Injectable;
    use Configurable;
    use Extensible;

    /**
     * @var LLMService
     */
    private $llmService;

    public function __construct(LLMService $llmService = null)
    {
        $this->llmService = $llmService ?: LLMService::singleton();
    }

    /**
     * Get the field structure for a given page
     *
     * @param DataObject $page
     * @return array
     */
    public function getPageFieldStructure(DataObject $page): array
    {
        $fields = [];
        $scaffoldFields = $page->getCMSFields();

        // Get basic field information (name, title, type)
        foreach ($scaffoldFields->dataFields() as $field) {
            if ($this->isContentField($field)) {
                $fields[] = [
                    'name' => $field->getName(),
                    'title' => $field->Title(),
                    'type' => get_class($field),
                    'description' => $field->getDescription()
                ];
            }
        }

        // Check for elemental blocks if the page has the ElementalPageExtension
        if ($page->hasExtension(ElementalPageExtension::class)) {
            $elementalAreas = $this->getElementalAreas($page);
            foreach ($elementalAreas as $areaName => $area) {
                $fields[] = [
                    'name' => $areaName,
                    'title' => 'Content Blocks',
                    'type' => 'ElementalArea',
                    'allowedElementTypes' => $this->getAllowedElementTypes($page, $areaName),
                    'description' => 'Content blocks area'
                ];
            }
        }

        return $fields;
    }

    /**
     * Check if the field is a content field that should be populated
     *
     * @param FormField $field
     * @return bool
     */
    protected function isContentField(FormField $field): bool
    {
        $contentFieldTypes = [
            TextField::class,
            TextareaField::class,
            HTMLEditorField::class,
        ];

        $this->extend('updateContentFieldTypes', $contentFieldTypes);

        // Check if the field is of a type that can contain content
        foreach ($contentFieldTypes as $fieldType) {
            if ($field instanceof $fieldType) {
                return true;
            }
        }

        // Exclude certain field names by convention
        $excludedFieldNames = [
            'ID', 'Created', 'LastEdited', 'ClassName', 'URLSegment',
            'ShowInMenus', 'ShowInSearch', 'Sort', 'ParentID', 'Version',
        ];

        if (in_array($field->getName(), $excludedFieldNames)) {
            return false;
        }

        return false;
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
                $areas[$relationName] = $page->$relationName();
            }
        }

        return $areas;
    }

    /**
     * Get the allowed element types for an elemental area
     *
     * @param DataObject $page
     * @param string $areaName
     * @return array
     */
    protected function getAllowedElementTypes(DataObject $page, string $areaName): array
    {
        $allowedTypes = [];
        $scaffoldFields = $page->getCMSFields();

        // Try to find the GridField for the elemental area
        $gridField = $scaffoldFields->dataFieldByName($areaName);
        if (!$gridField) {
            return $this->getAllElementTypes();
        }

        $config = $gridField->getConfig();
        $addNewMultiClass = $config->getComponentByType(GridFieldAddNewMultiClass::class);

        if ($addNewMultiClass) {
            $classes = $addNewMultiClass->getClasses($gridField);
            foreach ($classes as $className => $title) {
                $allowedTypes[] = [
                    'class' => $className,
                    'title' => $title,
                    'fields' => $this->getElementFields($className)
                ];
            }
        } else {
            // If we can't find the specific allowed types, return all available element types
            $allowedTypes = $this->getAllElementTypes();
        }

        return $allowedTypes;
    }

    /**
     * Get all available element types
     *
     * @return array
     */
    protected function getAllElementTypes(): array
    {
        $elementTypes = [];

        // Only proceed if Elemental is installed
        if (!class_exists(BaseElement::class)) {
            return $elementTypes;
        }

        $classes = ClassInfo::subclassesFor(BaseElement::class);

        // Remove the base class
        array_shift($classes);

        foreach ($classes as $className) {
            // Skip abstract classes
            $reflector = new \ReflectionClass($className);
            if ($reflector->isAbstract()) {
                continue;
            }

            $singleton = singleton($className);
            $elementTypes[] = [
                'class' => $className,
                'title' => $singleton->getType(),
                'fields' => $this->getElementFields($className)
            ];
        }

        return $elementTypes;
    }

    /**
     * Get the fields for an element type
     *
     * @param string $className
     * @return array
     */
    protected function getElementFields(string $className): array
    {
        $fields = [];

        if (!class_exists($className)) {
            return $fields;
        }

        $singleton = singleton($className);
        $scaffoldFields = $singleton->getCMSFields();

        foreach ($scaffoldFields->dataFields() as $field) {
            if ($this->isContentField($field)) {
                $fields[] = [
                    'name' => $field->getName(),
                    'title' => $field->Title(),
                    'type' => get_class($field),
                    'description' => $field->getDescription()
                ];
            }
        }

        return $fields;
    }

    /**
     * Generate content for a page based on a prompt
     *
     * @param DataObject $page
     * @param string $prompt
     * @return array The generated content in a structured format
     * @throws Exception
     */
    public function generateContent(DataObject $page, string $prompt): array
    {
        $structure = $this->getPageFieldStructure($page);
        $structureDescription = $this->formatStructureForPrompt($structure);

        $fullPrompt = <<<EOT
            I need to generate content for a web page with the following structure:

            $structureDescription

            Based on this user prompt, generate appropriate content for all the fields:
            "$prompt"

            Please return the content in YAML format that can be used to populate the page fields. For HTML content fields,
            include proper HTML markup with paragraphs, headings, etc. For elemental areas, include YAML that defines
            which blocks to create and what content to put in those blocks.
        EOT;

        $generatedYaml = $this->llmService->generateContent($fullPrompt);

        // Attempt to parse the YAML
        try {
            $parser = new \Symfony\Component\Yaml\Parser();
            $content = $parser->parse($generatedYaml);

            // If the parser doesn't return an array or returns an empty array, throw an exception
            if (!is_array($content) || empty($content)) {
                throw new Exception("Failed to parse generated content as YAML");
            }

            return $content;
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            // The YAML might be embedded in the response text, try to extract it
            if (preg_match('/```(?:yaml|yml)(.*?)```/s', $generatedYaml, $matches)) {
                $yamlContent = trim($matches[1]);
                $content = $parser->parse($yamlContent);

                if (!is_array($content) || empty($content)) {
                    throw new Exception("Failed to parse extracted YAML from response");
                }

                return $content;
            }

            throw new Exception("Failed to parse content: " . $e->getMessage());
        }
    }

    /**
     * Format the page structure in a way that can be included in a prompt
     *
     * @param array $structure
     * @return string
     */
    protected function formatStructureForPrompt(array $structure): string
    {
        $result = '';

        foreach ($structure as $field) {
            if ($field['type'] === 'ElementalArea') {
                $result .= "- {$field['title']} ({$field['name']}): A content blocks area that can contain the following types of blocks:\n";

                foreach ($field['allowedElementTypes'] as $elementType) {
                    $result .= "  - {$elementType['title']} ({$elementType['class']}): With fields:\n";

                    foreach ($elementType['fields'] as $elementField) {
                        $description = $elementField['description'] ? " - {$elementField['description']}" : '';
                        $result .= "    - {$elementField['title']} ({$elementField['name']}){$description}\n";
                    }
                }
            } else {
                $description = $field['description'] ? " - {$field['description']}" : '';
                $result .= "- {$field['title']} ({$field['name']}): Field type {$field['type']}{$description}\n";
            }
        }

        return $result;
    }
}
