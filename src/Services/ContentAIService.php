<?php

namespace KhalsaJio\ContentCreator\Services;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\ORM\DataObject;
use KhalsaJio\AI\Nexus\LLMClient;
use SilverStripe\Core\Injector\Injector;
use KhalsaJio\AI\Nexus\Util\SafetyManager;
use Symfony\Component\Yaml\Parser;
use KhalsaJio\AI\Nexus\Provider\DefaultStreamResponseHandler;

/**
 * Service responsible for generating content using AI models
 * This is a refactored version of the original ContentGeneratorService focusing only on content generation
 */
class ContentAIService extends BaseContentService
{
    /**
     * @var LLMClient
     */
    private $llmClient;

    /**
     * @var ContentStructureService
     */
    private $structureService;

    /**
     * @var ContentPopulatorService
     */
    private $populatorService;

    private static $use_compact_structure = false;

    private static $custom_system_prompt = null;

    /**
     * Constructor
     *
     * @param LLMClient|null $llmClient
     * @param ContentStructureService|null $structureService
     * @param ContentPopulatorService|null $populatorService
     * @param ContentCacheService|null $cacheService
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        LLMClient $llmClient = null,
        ContentStructureService $structureService = null,
        ContentPopulatorService $populatorService = null,
        ContentCacheService $cacheService = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($cacheService, $logger);

        $this->llmClient = $llmClient ?: Injector::inst()->get(LLMClient::class);
        $this->structureService = $structureService ?: Injector::inst()->get(ContentStructureService::class);
        $this->populatorService = $populatorService ?: Injector::inst()->get(ContentPopulatorService::class);
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
        $systemPrompt = $this->buildSystemPrompt($page);

        // Prepare the message payload for LLMClient
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt, 'cache_control' => ['type' => "ephemeral"]],
                ['role' => 'user', 'content' => $prompt]
            ],
        ];

        $this->logger->info("Making LLM API call for content generation with retry");
        $response = $this->llmClient->chatWithRetry($payload, 'chat/completions');

        $generatedOutput = $response['content'] ?? '';
        return $this->parseGeneratedContent($generatedOutput);
    }

    /**
     * Generate content in a streaming fashion for a DataObject based on a prompt
     *
     * @param DataObject $dataObject The object to generate content for
     * @param string $prompt The user's prompt for content generation
     * @param int $maxTokens Maximum tokens to generate
     * @param float $temperature Controls randomness (0.0-1.0)
     * @param callable $chunkCallback Callback for each text chunk
     * @param callable $completeCallback Callback when generation is complete
     * @param callable $errorCallback Callback for errors
     * @throws Exception If stream cannot be initialized
     */
    public function generateStreamContent(
        DataObject $dataObject,
        string $prompt,
        int $maxTokens = 4000,
        float $temperature = 0.7,
        callable $chunkCallback = null,
        callable $completeCallback = null,
        callable $errorCallback = null
    ): void {
        // Build the system prompt
        $systemPrompt = $this->buildSystemPrompt($dataObject);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt, 'cache_control' => ['type' => "ephemeral"]],
            ['role' => 'user', 'content' => $prompt]
        ];

        // Apply safety instructions
        $messages = SafetyManager::addSafetyInstructions($messages);

        $payload = [
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => true
        ];

        $fullContentBuffer = [];

        // handler for stream processing
        $handler = new DefaultStreamResponseHandler(
            function ($text, $chunk, $provider, $model) use (&$fullContentBuffer, $chunkCallback) {
                if (!empty($text)) {
                    $fullContentBuffer[] = $text;
                    if ($chunkCallback) {
                        call_user_func($chunkCallback, $text);
                    }
                }
            },

            function ($completeContent, $usage) use (&$fullContentBuffer, $completeCallback, $errorCallback) {
                $finalText = implode('', $fullContentBuffer);

                try {
                    $parsedContent = $this->parseGeneratedContent($finalText);

                    if (!empty($parsedContent) && $completeCallback) {
                        call_user_func($completeCallback, $parsedContent, $usage);
                    }
                } catch (Exception $e) {
                    // If we have an error callback, report the error
                    if ($errorCallback) {
                        call_user_func($errorCallback, new Exception('Error parsing content: ' . $e->getMessage()));
                    }
                }
            },

            function ($exception) use ($errorCallback) {
                if ($errorCallback) {
                    call_user_func($errorCallback, $exception);
                }
            }
        );

        try {
            // Make the streaming API call
            $this->llmClient->streamChatWithRetry($payload, 'chat/completions', $handler);
        } catch (Exception $e) {
            $this->logger->error("Streaming content generation failed", [
                'error_message' => $e->getMessage(),
                'data_object_class' => get_class($dataObject),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Parse the generated content from a string to a structured array
     *
     * @param string $content
     * @return array
     */
    public function parseGeneratedContent(string $content): array
    {
        if (is_array($content)) {
            return $content;
        }

        $parser = new Parser();
        $originalContent = $content;

        // Step 1: Try direct parsing first as a quick win if content is clean
        try {
            $parsed = $parser->parse($content);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        } catch (Exception $e) {
            $this->logger->debug("Initial direct YAML parsing failed, will try cleanup strategies", [
                'error_message' => $e->getMessage(),
                'content_length' => strlen($content)
            ]);
        }

        // Step 2: Extract content from code blocks - check for ```yaml or ```yml blocks first
        if (preg_match('/```(?:yaml|yml)?\s*([\s\S]*?)```/s', $content, $matches)) {
            $yamlContent = trim($matches[1]);
            try {
                $parsed = $parser->parse($yamlContent);
                if (is_array($parsed) && !empty($parsed)) {
                    $this->logger->info("Successfully extracted and parsed YAML from code block");
                    return $parsed;
                }
            } catch (Exception $e) {
                $this->logger->debug("Failed to parse YAML from code block", [
                    'error_message' => $e->getMessage(),
                    'extracted_content_length' => strlen($yamlContent)
                ]);
            }
        }

        // Step 3: Detect and extract YAML-like structure from text
        $lines = explode("\n", $content);
        $yamlLines = [];
        $inYaml = false;
        $yamlStartPattern = '/^[A-Za-z0-9_-]+\s*:/';
        $yamlIndentPattern = '/^\s+[A-Za-z0-9_-]+\s*:/';
        $lastYamlLineIndex = -1;

        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);
            
            if ($trimmedLine === '') {
                if ($inYaml) {
                    $yamlLines[] = $line;
                }
                continue;
            }
            
            // Check if this looks like a YAML line (either a top-level key or indented key)
            $isYamlLine = preg_match($yamlStartPattern, $trimmedLine) || 
                            preg_match($yamlIndentPattern, $trimmedLine) ||
                            (($inYaml && $lastYamlLineIndex === $index - 1) && 
                                (preg_match('/^\s*-\s+/', $trimmedLine) ||
                                preg_match('/^\s+/', $trimmedLine)));
            
            if ($isYamlLine) {
                $inYaml = true;
                $yamlLines[] = $line;
                $lastYamlLineIndex = $index;
            } else if ($inYaml) {
                if (preg_match('/^\s+/', $trimmedLine) && !preg_match('/^[A-Za-z0-9_]+:/', $trimmedLine)) {
                    // This is likely an indented continuation of a multiline value
                    $yamlLines[] = $line;
                    $lastYamlLineIndex = $index;
                } else {
                    // Check if we have enough YAML content to be worth testing
                    if (count($yamlLines) >= 2) {
                        break;
                    } else {
                        // Not enough YAML content, keep looking
                        $inYaml = false;
                        $yamlLines = [];
                    }
                }
            }
        }
        
        if (count($yamlLines) >= 2) {
            $extractedYaml = implode("\n", $yamlLines);
            try {
                $parsed = $parser->parse($extractedYaml);
                if (is_array($parsed) && !empty($parsed)) {
                    $this->logger->info("Successfully extracted and parsed YAML from mixed content");
                    return $parsed;
                }
            } catch (Exception $e) {
                $this->logger->debug("Extracted content failed to parse as YAML", [
                    'error_message' => $e->getMessage(),
                    'extracted_content_length' => strlen($extractedYaml)
                ]);
            }
        }

        // Step 4: More aggressive extraction - find the first valid YAML key and extract from there
        if (preg_match_all('/^([A-Za-z0-9_-]+)\s*:/m', $content, $matches, PREG_OFFSET_CAPTURE)) {
            if (!empty($matches[0])) {
                foreach ($matches[0] as $match) {
                    $startPos = $match[1];
                    $potentialYaml = substr($content, $startPos);
                    
                    try {
                        $parsed = $parser->parse($potentialYaml);
                        if (is_array($parsed) && !empty($parsed)) {
                            $this->logger->info("Successfully parsed YAML with aggressive extraction");
                            return $parsed;
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
            }
        }

        return ['content' => $originalContent, 'parsing_error' => 'Could not parse as valid YAML'];
    }

    /**
     * Create a system prompt for the AI to generate content based on the page structure
     *
     * @param DataObject $dataObject
     * @return string
     */
    public function buildSystemPrompt(DataObject $dataObject): string
    {
        $structure = $this->structureService->getPageFieldStructure($dataObject);

        if ($this->config()->get('use_compact_structure')) {
            $structureDescription = $this->formatCompactStructureForPrompt($structure);
        } else {
            $structureDescription = $this->formatStructureForPrompt($structure);
        }

        $customPrompt = $this->config()->get('custom_system_prompt');

        if ($customPrompt) {
            $systemPrompt = str_replace('{structure}', $structureDescription, $customPrompt);
        } else {
            $systemPrompt = <<<EOT
                You are an AI content generator for SilverStripe pages. Your task is to generate structured content based on the provided page structure.
                The page structure is as follows:

                $structureDescription

                IMPORTANT RULES FOR GENERATING CONTENT:

                1. Fields are formatted as "Title (FieldName): Type - Description". Use the EXACT field name in brackets in your YAML output.
                2. For example, if you see "Page Title (Title): Text", your generated YAML should use "Title:" as the field key.
                3. DO NOT include any explanatory text, commentary, or notes before or after the YAML content.
                4. ONLY output valid YAML. Do not wrap the YAML in code blocks or backticks.
                5. Begin your response immediately with the first YAML key, without any introduction.
                6. Dropdowns and options should select from the provided options.
                7. Elemental areas should specify which blocks to create and their content.
                8. Relationship fields (has_one, has_many, many_many) should include appropriate related object data.
                9. For relationship fields:
                    - Single related items (has_one): Provide an object with fields relevant to the related class
                    - Multiple related items (has_many/many_many): Provide an array of objects with fields relevant to the related class
                    - When relationship fields appear within elemental blocks, follow the same pattern
                    - Pay attention to relationship descriptions that explain what kind of data is expected
                10. For elemental blocks:
                    - Always include the BlockType or ClassName field with the full class name shown in brackets
                    - For example, if you see "Content Block (ContentBlock\\Block)", use "BlockType: ContentBlock\\Block" in your YAML
                    - You can provide blocks as direct key-value pairs OR as an array under an 'elements' key
                    - Example formats: 
                        - Direct: { "ElementalArea": { "Block1": {...}, "Block2": {...} } }
                        - Array: { "ElementalArea": [ {"BlockType": "Class1", ...}, {"BlockType": "Class2", ...} ] }
                        - Nested: { "ElementalArea": { "elements": [ {"BlockType": "Class1", ...}, {"BlockType": "Class2", ...} ] } }
            EOT;
        }

        return $systemPrompt;
    }

    /**
     * Format the page structure in a way that can be included in a prompt
     *
     * @param array $structure
     * @return string
     */
    public function formatStructureForPrompt(array $structure): string
    {
        $result = '';

        $fields = isset($structure['fields']) ? $structure['fields'] : $structure;

        $standardFields = [];
        $relationshipFields = [];
        $elementalAreas = [];

        // categorize fields to optimize presentation
        foreach ($fields as $field) {
            if ($field['type'] === 'ElementalArea') {
                $elementalAreas[] = $field;
            } elseif (in_array($field['type'], ['has_one', 'has_many', 'many_many', 'belongs_many_many'])) {
                $relationshipFields[] = $field;
            } else {
                $standardFields[] = $field;
            }
        }

        // Format standard fields first (typically shorter)
        if (!empty($standardFields)) {
            $result .= "CONTENT FIELDS:\n";
            foreach ($standardFields as $field) {
                $description = !empty($field['description']) ? " - {$field['description']}" : '';
                $fieldType = $field['type'];

                $simpleType = $this->getShortClassName($fieldType);

                $result .= "- {$field['title']} ({$field['name']}): {$simpleType}{$description}";

                if (isset($field['options']) && !empty($field['options'])) {
                    $result .= " Options: " . $this->formatOptionsForPrompt($field['options'], true);
                }

                $result .= "\n";
            }
            $result .= "\n";
        }

        // relationship fields
        if (!empty($relationshipFields)) {
            $result .= "RELATIONSHIPS:\n";
            foreach ($relationshipFields as $field) {
                $description = !empty($field['description']) ? " - {$field['description']}" : '';
                $fieldType = $field['type'];
                $simpleType = $this->getShortClassName($fieldType);
                $result .= "- {$field['title']} ({$field['name']}) - {$simpleType}{$description}\n";

                // Include the fields of the related class if available
                if (isset($field['fields']) && is_array($field['fields']) && !empty($field['fields'])) {
                    $result .= "  With fields:\n";
                    foreach ($field['fields'] as $relatedField) {
                        $relatedDescription = !empty($relatedField['description']) ? " - {$relatedField['description']}" : '';
                        $fieldType = $relatedField['type'];
                        $simpleType = $this->getShortClassName($fieldType);

                        $result .= "    - {$relatedField['title']} ({$relatedField['name']}): {$simpleType}{$relatedDescription}\n";

                        if (isset($relatedField['options']) && !empty($relatedField['options'])) {
                            $result .= "      Options: " . $this->formatOptionsForPrompt($relatedField['options'], true) . "\n";
                        }
                    }
                }
            }
            $result .= "\n";
        }

        // ElementalAreas
        if (!empty($elementalAreas)) {
            $result .= "CONTENT BLOCKS:\n";

            $elementTypeDescriptions = [];

            foreach ($elementalAreas as $field) {
                $result .= "- {$field['title']} ({$field['name']}): Can contain these block types:\n";

                foreach ($field['allowedElementTypes'] as $elementType) {
                    $fullClassName = $this->unsanitiseClassName($elementType['class']);

                    $result .= "  - {$elementType['title']} ({$fullClassName})";

                    if (!isset($elementTypeDescriptions[$fullClassName])) {
                        $result .= ": With fields:\n";

                        $elemStandardFields = [];
                        $elemRelationFields = [];
                        $elemNestedAreas = [];

                        foreach ($elementType['fields'] as $elementField) {
                            if ($elementField['type'] === 'ElementalArea') {
                                $elemNestedAreas[] = $elementField;
                            } elseif (in_array($elementField['type'], ['has_one', 'has_many', 'many_many', 'belongs_many_many'])) {
                                $elemRelationFields[] = $elementField;
                            } else {
                                $elemStandardFields[] = $elementField;
                            }
                        }

                        // Standard fields first
                        foreach ($elemStandardFields as $elementField) {
                            $description = !empty($elementField['description']) ? " - {$elementField['description']}" : '';
                            $fieldType = $elementField['type'];
                            $simpleType = $this->getShortClassName($fieldType);

                            $result .= "    - {$elementField['title']} ({$elementField['name']}): {$simpleType}{$description}";

                            if (isset($elementField['options']) && !empty($elementField['options'])) {
                                $result .= " Options: " . $this->formatOptionsForPrompt($elementField['options'], true);
                            }

                            $result .= "\n";
                        }

                        // Then relationship fields
                        foreach ($elemRelationFields as $elementField) {
                            $description = !empty($elementField['description']) ? " - {$elementField['description']}" : '';
                            $fieldType = $elementField['type'];
                            $simpleType = $this->getShortClassName($fieldType);
                            $result .= "    - {$elementField['title']} ({$elementField['name']}) - {$simpleType}{$description}\n";

                            // Include the fields of the related class if available
                            if (isset($elementField['fields']) && is_array($elementField['fields']) && !empty($elementField['fields'])) {
                                $result .= "      With fields:\n";
                                foreach ($elementField['fields'] as $relatedField) {
                                    $relatedDescription = !empty($relatedField['description']) ? " - {$relatedField['description']}" : '';
                                    $fieldType = $relatedField['type'];

                                    $simpleType = $this->getShortClassName($fieldType);
                                    $result .= "        - {$relatedField['title']} ({$relatedField['name']}): {$simpleType}{$relatedDescription}";

                                    if (isset($relatedField['options']) && !empty($relatedField['options'])) {
                                        $result .= " Options: " . $this->formatOptionsForPrompt($relatedField['options'], true);
                                    }

                                    $result .= "\n";
                                }
                            }
                        }

                        // Then nested ElementalAreas within the block
                        foreach ($elemNestedAreas as $elementField) {
                            $description = !empty($elementField['description']) ? " - {$elementField['description']}" : '';
                            $result .= "    - {$elementField['title']} ({$elementField['name']}) - ElementalArea{$description}";
                            $result .= ": Can contain these nested block types:\n";

                            // Process the allowed element types for this nested area
                            if (isset($elementField['allowedElementTypes']) && !empty($elementField['allowedElementTypes'])) {
                                foreach ($elementField['allowedElementTypes'] as $nestedElementType) {
                                    $nestedFullClassName = $this->unsanitiseClassName($nestedElementType['class']);
                                    $result .= "      - {$nestedElementType['title']} ({$nestedFullClassName})";

                                    // If already described elsewhere, then just reference that it's already described
                                    if (isset($elementTypeDescriptions[$nestedFullClassName])) {
                                        $result .= " (fields described above)\n";
                                    } else {
                                        $result .= "\n";
                                    }
                                }
                            }
                        }

                        $elementTypeDescriptions[$fullClassName] = true;
                    } else {
                        $result .= " (fields described above)\n";
                    }
                }
                $result .= "\n";
            }
        }

        return rtrim($result);
    }

    /**
     * Format the structure in a compact way for prompt usage
     * This is a more token-efficient version of formatStructureForPrompt
     *
     * @param array $structure
     * @return string
     */
    public function formatCompactStructureForPrompt(array $structure): string
    {
        $result = '';
        $fields = isset($structure['fields']) ? $structure['fields'] : $structure;

        $standardFields = [];
        $relationshipFields = [];
        $elementalAreas = [];

        foreach ($fields as $field) {
            if ($field['type'] === 'ElementalArea') {
                $elementalAreas[] = $field;
            } elseif (in_array($field['type'], ['has_one', 'has_many', 'many_many', 'belongs_many_many'])) {
                $relationshipFields[] = $field;
            } else {
                $standardFields[] = $field;
            }
        }

        // compact standard fields
        if (!empty($standardFields)) {
            $result .= "FIELDS:\n";
            foreach ($standardFields as $field) {
                $result .= "- {$field['title']} ({$field['name']}): {$this->getShortClassName($field['type'])}";

                if (!empty($field['description'])) {
                    $result .= " - {$field['description']}";
                }

                if (isset($field['options']) && !empty($field['options'])) {
                    $result .= " " . $this->formatOptionsForPrompt($field['options']);
                }

                $result .= "\n";
            }
        }

        // Compact relationships with essential fields only
        if (!empty($relationshipFields)) {
            $result .= "RELATIONS:\n";
            foreach ($relationshipFields as $field) {
                $result .= "- {$field['title']} ({$field['name']}) - {$this->getShortClassName($field['type'])}";

                if (isset($field['fields']) && !empty($field['fields'])) {
                    $result .= " [";
                    $compactFields = [];
                    foreach ($field['fields'] as $relField) {
                        $compactFields[] = "{$relField['name']}:{$this->getShortClassName($relField['type'])}";
                    }
                    $result .= implode(',', $compactFields) . "]";
                }

                $result .= "\n";
            }
        }

        // compact elemental areas
        if (!empty($elementalAreas)) {
            $result .= "BLOCKS:\n";
            $blockDefs = [];

            foreach ($elementalAreas as $field) {
                $result .= "- {$field['name']}: ";
                $blockTypes = [];

                foreach ($field['allowedElementTypes'] as $elementType) {
                    $className = $this->unsanitiseClassName($elementType['class']);
                    $blockTypes[] = $className;

                    if (!isset($blockDefs[$className])) {
                        $blockDefs[$className] = $this->formatBlockDefinition($elementType);
                    }
                }

                $result .= implode('|', $blockTypes) . "\n";
            }

            foreach ($blockDefs as $className => $definition) {
                $result .= "{$className}: {$definition}\n";
            }
        }

        return rtrim($result);
    }

    /**
     * Format block definition in most compact way possible
     *
     * @param array $elementType
     * @return string
     */
    private function formatBlockDefinition(array $elementType): string
    {
        $fields = [];
        $nestedAreas = [];

        foreach ($elementType['fields'] as $field) {
            if ($field['type'] === 'ElementalArea') {
                $allowedTypes = [];
                if (isset($field['allowedElementTypes'])) {
                    foreach ($field['allowedElementTypes'] as $nestedType) {
                        $allowedTypes[] = $this->unsanitiseClassName($nestedType['class']);
                    }
                }
                $nestedAreas[] = "{$field['name']}:[" . implode('|', $allowedTypes) . "]";
            } else {
                $fieldDef = "{$field['name']}:{$this->getShortClassName($field['type'])}";

                if (isset($field['options']) && !empty($field['options'])) {
                    $fieldDef .= $this->formatOptionsForPrompt($field['options']);
                }

                // Add relationship field info inline
                if (in_array($field['type'], ['has_one', 'has_many', 'many_many']) && isset($field['fields'])) {
                    $relFields = [];
                    foreach ($field['fields'] as $relField) {
                        $relFields[] = "{$relField['name']}:{$this->getShortClassName($relField['type'])}";
                    }
                    $fieldDef .= "[" . implode(',', $relFields) . "]";
                }

                $fields[] = $fieldDef;
            }
        }

        $result = implode(',', $fields);
        if (!empty($nestedAreas)) {
            $result .= "|" . implode(',', $nestedAreas);
        }

        return $result;
    }

    /**
     * Format field options into a human-readable string for the prompt
     *
     * @param array $options The options to format
     * @param bool $condensed If true, uses a more token-efficient format
     * @return string
     */
    protected function formatOptionsForPrompt(array $options, bool $condensed = false): string
    {
        if ($condensed) {
            // Create a more compact representation for token efficiency
            $keys = array_keys($options);

            // If keys are numeric (0,1,2...), just show values
            if (count($keys) > 0 && is_numeric($keys[0]) && $keys[0] == 0) {
                $values = array_values($options);
                return "[" . implode(", ", $values) . "]";
            }

            // Show all key-value pairs for completeness
            $samples = [];
            foreach ($options as $key => $value) {
                if (is_array($value)) {
                    $samples[] = "$key: [nested]";
                } else {
                    $samples[] = "$key: $value";
                }
            }

            return "[" . implode(", ", $samples) . "]";
        }

        // Original verbose format
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
     * Create a complete content generation and population workflow
     *
     * @param DataObject $dataObject
     * @param string $prompt
     * @param bool $write Whether to write the object after population
     * @return DataObject
     * @throws Exception
     */
    public function generateAndPopulateContent(DataObject $dataObject, string $prompt, bool $write = true): DataObject
    {
        // Generate content using AI
        $generatedContent = $this->generateContent($dataObject, $prompt);

        // Populate the object with generated content
        return $this->populatorService->populateContent($dataObject, $generatedContent, $write);
    }
}
