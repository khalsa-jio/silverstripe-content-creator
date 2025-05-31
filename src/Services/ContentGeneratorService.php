<?php

namespace KhalsaJio\ContentCreator\Services;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Forms\URLField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Extensible;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\EmailField;
use Symfony\Component\Yaml\Parser;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DatetimeField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\OptionsetField;
use KhalsaJio\AI\Nexus\LLMClient;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementalArea;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
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
     * List of system field names to exclude from content generation
     *
     * @config
     * @var array
     */
    private static $excluded_field_names = [
        'ID', 'Created', 'LastEdited', 'ClassName', 'URLSegment',
        'ShowInMenus', 'ShowInSearch', 'Sort', 'ParentID', 'Version',
        'RecordClassName', 'ParentClass', 'OwnerClassName', 'ElementID',
        'CMSEditLink', 'ExtraClass', 'InlineEditable', 'Title'
    ];

    /**
     * @var LLMClient
     */
    private $llmClient;

    /**
     * @var ContentCacheService
     */
    private $cacheService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LLMClient $llmClient = null, ContentCacheService $cacheService = null, LoggerInterface $logger = null)
    {
        $this->llmClient = $llmClient ?: Injector::inst()->get(LLMClient::class);
        $this->cacheService = $cacheService ?: Injector::inst()->get(ContentCacheService::class);
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

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

        return $this->cacheService->getOrCreate($cacheKey, function () use ($dataObject) {
            return $this->generatePageFieldStructure($dataObject);
        });
    }

    /**
     * This generates the field structure for a given DataObject, If not cached
     *
     * @param DataObject $dataObject
     * @return array
     */
    protected function generatePageFieldStructure(DataObject $dataObject): array
    {
        $fields = [];
        $scaffoldFields = $dataObject->getCMSFields();

        foreach ($scaffoldFields->dataFields() as $field) {
            if ($this->isContentField($field)) {
                $fieldData = [
                    'name' => $field->getName(),
                    'title' => $field->Title(),
                    'type' => get_class($field),
                    'description' => $field->getDescription()
                ];

                // Add field options if available
                $options = $this->getFieldOptions($field);
                if ($options !== null) {
                    $fieldData['options'] = $options;
                }

                $fields[] = $fieldData;
            }
        }

        // Check for elemental blocks if the DataObject(Page) has the ElementalPageExtension
        if ($dataObject->hasExtension(ElementalPageExtension::class)) {
            $elementalAreas = $this->getElementalAreas($dataObject);
            foreach ($elementalAreas as $areaName => $area) {
                $fields[] = [
                    'name' => $areaName,
                    'title' => 'Content Blocks',
                    'type' => 'ElementalArea',
                    'allowedElementTypes' => $this->getAllowedElementTypes($dataObject, $areaName),
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
            DropdownField::class,
            TreeDropdownField::class,
            OptionsetField::class,
            EmailField::class,
            URLField::class,
            DateField::class,
            DatetimeField::class,
            ListboxField::class,
        ];

        $this->extend('updateContentFieldTypes', $contentFieldTypes);

        // Check if the field is of a type that can contain content
        foreach ($contentFieldTypes as $fieldType) {
            if ($field instanceof $fieldType) {
                return true;
            }
        }

        $excludedFieldNames = $this->config()->get('excluded_field_names') ?: [];

        $this->extend('updateExcludedFieldNames', $excludedFieldNames);

        // If the field name is in the excluded list, it's not a content field
        if (in_array($field->getName(), $excludedFieldNames)) {
            return false;
        }

        // Check field name patterns that indicate content fields
        $contentFieldNamePatterns = [
            '/content$/i',    // Fields ending with "content"
            '/^content/i',    // Fields starting with "content"
            '/text$/i',       // Fields ending with "text"
            '/^text/i',       // Fields starting with "text"
            '/description$/i' // Fields ending with "description"
        ];

        $this->extend('updateContentFieldNamePatterns', $contentFieldNamePatterns);

        // Check if the field name matches any content field pattern
        $fieldName = $field->getName();
        foreach ($contentFieldNamePatterns as $pattern) {
            if (preg_match($pattern, $fieldName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get field options for fields like DropdownField, CheckboxField etc.
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

        if ($field instanceof TreeDropdownField) {
            $treeClass = $field->getSourceObject();
            $titleField = $field->getLabelField();
            return ['type' => 'TreeSelection', 'class' => $treeClass, 'titleField' => $titleField];
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
            $gridField = $scaffoldFields->dataFieldByName($areaName . 'ID');

            if (!$gridField && $page->hasExtension(ElementalPageExtension::class)) {
                $relationName = $areaName;
                if (method_exists($page, $relationName) && $page->$relationName() instanceof ElementalArea) {
                    return $this->getAllElementTypes();
                }

                return $this->getAllElementTypes();
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
                        'fields' => $this->getElementFields($normalizedClassName)
                    ];
                }

                if (!empty($allowedTypes)) {
                    return $allowedTypes;
                }
            }
        }

        return $this->getAllElementTypes();
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
                $fieldData = [
                    'name' => $field->getName(),
                    'title' => $field->Title() ?: $field->getName(),
                    'type' => get_class($field),
                    'description' => $field->getDescription() ?: '',
                ];

                // Add field options if available
                $options = $this->getFieldOptions($field);
                if ($options !== null) {
                    $fieldData['options'] = $options;
                }

                $fields[] = $fieldData;
            }
        }

        // Check if this element has DB fields that weren't captured by CMS fields
        // This is important for custom elements that might not expose all fields in getCMSFields
        $dbFields = $singleton->config()->get('db');
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

        $uniqueFields = [];
        $fieldNames = [];

        foreach ($fields as $field) {
            if (!in_array($field['name'], $fieldNames)) {
                $fieldNames[] = $field['name'];
                $uniqueFields[] = $field;
            }
        }

        return $uniqueFields;
    }

    /**
     * Format a field name into a human-readable title
     *
     * @param string $fieldName
     * @return string
     */
    protected function formatFieldTitle(string $fieldName): string
    {
        $title = preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' $0', $fieldName);
        $title = str_replace('_', ' ', $title);
        return ucfirst(trim($title));
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

            Please return the content in YAML format only. No explanatory text before or after the YAML.
            For HTML content fields, include proper HTML markup.
            For fields with options (like dropdown fields, checkbox fields, etc.), choose values from the provided options.
            For elemental areas, include which blocks to create with proper class names and what content to put in those blocks.
        EOT;

        // Prepare the message payload for LLMClient
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful content creation assistant.'],
                ['role' => 'user', 'content' => $fullPrompt]
            ],
        ];

        // Call the LLMClient
        $response = $this->llmClient->chat($payload, 'chat/completions');

        // Extract the generated content from the response
        if (isset($response['error']) && !empty($response['error'])) {
            $errorMessage = is_array($response['error']) ? ($response['error']['message'] ?? 'Unknown error') : $response['error'];
            throw new Exception("Error generating content: " . $errorMessage);
        }

        $generatedOutput = $response['content'] ?? '';
        $parser = new Parser();

        // Try direct parsing first
        try {
            $content = $parser->parse($generatedOutput);
            if (is_array($content) && !empty($content)) {
                return $content;
            }
        } catch (Exception $e) {
            // Try to extract YAML from code block if direct parsing fails
            if (preg_match('/```(?:yaml|yml)?\\s*([\\s\\S]*?)```/s', $generatedOutput, $matches)) {
                $yamlContent = trim($matches[1]);
                try {
                    $content = $parser->parse($yamlContent);
                    if (is_array($content) && !empty($content)) {
                        return $content;
                    }
                } catch (Exception $e) {
                    // Failed to parse extracted content
                    $this->logger->error("Failed to parse YAML from AI response: " . $e->getMessage(), [
                        'response' => $generatedOutput,
                        'yamlContent' => $yamlContent
                    ]);
                }
            }
        }

        throw new Exception("Could not parse valid YAML from the AI response. Please try again.");
    }

    public function buildSystemPrompt(DataObject $dataObject): string
    {
        $structure = $this->getPageFieldStructure($dataObject);
        $structureDescription = $this->formatStructureForPrompt($structure);

        $systemPrompt = <<<EOT
            You are an AI content generator for SilverStripe pages. Your task is to generate structured content based on the provided page structure.

            The page structure is as follows:

            $structureDescription

            Use this structure to generate content that fits the fields, including:
            - Text fields should contain relevant text.
            - HTML fields should include proper HTML markup.
            - Dropdowns and options should select from the provided options.
            - Elemental areas should specify which blocks to create and their content.

            Always return the generated content in YAML format only, without any additional text.
        EOT;

        return $systemPrompt;
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
                    // Unsanitize class name for proper display
                    $className = $this->unsanitiseClassName($elementType['class']);
                    $result .= "  - {$elementType['title']} ({$className}): With fields:\n";

                    foreach ($elementType['fields'] as $elementField) {
                        $description = $elementField['description'] ? " - {$elementField['description']}" : '';
                        $result .= "    - {$elementField['title']} ({$elementField['name']}){$description}";

                        // Include field options if available
                        if (isset($elementField['options'])) {
                            $result .= " Options: " . $this->formatOptionsForPrompt($elementField['options']);
                        }

                        $result .= "\n";
                    }
                }
            } else {
                $description = $field['description'] ? " - {$field['description']}" : '';
                $result .= "- {$field['title']} ({$field['name']}): Field type {$field['type']}{$description}";

                // Include field options if available
                if (isset($field['options'])) {
                    $result .= " Options: " . $this->formatOptionsForPrompt($field['options']);
                }

                $result .= "\n";
            }
        }

        return $result;
    }

    /**
     * Format field options into a human-readable string for the prompt
     *
     * @param array $options
     * @return string
     */
    protected function formatOptionsForPrompt(array $options): string
    {
        if (isset($options['type']) && $options['type'] === 'TreeSelection') {
            return "[Tree selection from {$options['class']}, using field '{$options['titleField']}']";
        }

        // For standard key-value option arrays
        $formattedOptions = [];
        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $formattedOptions[] = "$key: [nested options]";
            } else {
                $formattedOptions[] = "$key: $value";
            }
        }

        return "[" . implode(", ", $formattedOptions) . "]";
    }

    /**
     * Unsanitise a model class name
     *
     * @param string $class
     * @return string
     */
    protected function unsanitiseClassName($class)
    {
        return str_replace('-', '\\', $class ?? '');
    }

    public function parseGeneratedContent(string $content): array
    {
        // If the content is already JSON/array like, return it as is
        if (is_array($content)) {
            return $content;
        }

        // Try to parse as YAML which seems to be the expected format from AI response
        $parser = new Parser();

        try {
            // Direct parsing attempt
            $parsed = $parser->parse($content);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        } catch (Exception $e) {
            // Try to extract YAML from code block if direct parsing fails
            if (preg_match('/```(?:yaml|yml)?\\s*([\\s\\S]*?)```/s', $content, $matches)) {
                try {
                    $yamlContent = trim($matches[1]);
                    $parsed = $parser->parse($yamlContent);
                    if (is_array($parsed) && !empty($parsed)) {
                        return $parsed;
                    }
                } catch (Exception $innerException) {
                    $this->logger->error("Failed to parse YAML from extracted code block", [
                        'exception' => $innerException->getMessage(),
                        'content' => $content
                    ]);
                }
            }
        }

        // If cannot parse, make a simple structure with the content
        $simpleContent = ['Content' => $content];
        return $simpleContent;
    }
}
