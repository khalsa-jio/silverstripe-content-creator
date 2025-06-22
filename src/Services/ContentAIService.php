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

        // Call the LLMClient
        $response = $this->llmClient->chat($payload, 'chat/completions');

        // Extract the generated content from the response
        if (isset($response['error']) && !empty($response['error'])) {
            $errorMessage = is_array($response['error']) ? ($response['error']['message'] ?? 'Unknown error') : $response['error'];
            throw new Exception("Error generating content: " . $errorMessage);
        }

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

        // Prepare messages
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt, 'cache_control' => ['type' => "ephemeral"]],
            ['role' => 'user', 'content' => $prompt]
        ];

        // Apply safety instructions
        $messages = SafetyManager::addSafetyInstructions($messages);

        // Prepare payload
        $payload = [
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => true
        ];

        // Create content buffer for collecting the full content
        $fullContentBuffer = [];

        // Create the handler for stream processing
        $handler = new DefaultStreamResponseHandler(
            // Chunk handler
            function ($text, $chunk, $provider, $model) use (&$fullContentBuffer, $chunkCallback) {
                if (!empty($text)) {
                    $fullContentBuffer[] = $text;
                    if ($chunkCallback) {
                        call_user_func($chunkCallback, $text);
                    }
                }
            },

            // Complete handler
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

            // Error handler
            function ($exception) use ($errorCallback) {
                if ($errorCallback) {
                    call_user_func($errorCallback, $exception);
                }
            }
        );

        try {
            // Initiate the streaming request
            $this->llmClient->streamChat($payload, 'chat/completions', $handler);
        } catch (Exception $e) {
            if ($errorCallback) {
                call_user_func($errorCallback, new Exception("Stream initiation failed: " . $e->getMessage()));
            } else {
                throw $e;
            }
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
        return ['content' => $content];
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

        $systemPrompt = <<<EOT
            Generate SilverStripe YAML content for page using this structure:

            $structureDescription

            Rules: Use FieldName for YAML keys, select from provided options, return YAML only.
        EOT;

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

        // Handle different structure formats
        $fields = isset($structure['fields']) ? $structure['fields'] : $structure;

        // Group standard fields and relationship fields
        $standardFields = [];
        $relationshipFields = [];
        $elementalAreas = [];

        // First pass - categorize fields to optimize presentation
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

                // Simplify field type for better LLM comprehension
                $simpleType = $this->getShortClassName($fieldType);

                $result .= "- {$field['title']} ({$field['name']}): {$simpleType}{$description}";

                // Include field options if available
                if (isset($field['options']) && !empty($field['options'])) {
                    $result .= " Options: " . $this->formatOptionsForPrompt($field['options'], true);
                }

                $result .= "\n";
            }
            $result .= "\n";
        }

        // Then format relationship fields
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

                        // Include field options if available
                        if (isset($relatedField['options']) && !empty($relatedField['options'])) {
                            $result .= "      Options: " . $this->formatOptionsForPrompt($relatedField['options'], true) . "\n";
                        }
                    }
                }
            }
            $result .= "\n";
        }

        // Finally, format the ElementalAreas (most complex)
        if (!empty($elementalAreas)) {
            $result .= "CONTENT BLOCKS:\n";

            // Store element type descriptions keyed by class name for reference
            $elementTypeDescriptions = [];

            foreach ($elementalAreas as $field) {
                $result .= "- {$field['title']} ({$field['name']}): Can contain these block types:\n";

                foreach ($field['allowedElementTypes'] as $elementType) {
                    // Use full class name for clarity and consistency
                    $fullClassName = $this->unsanitiseClassName($elementType['class']);

                    // Always show which blocks are available in this specific area
                    $result .= "  - {$elementType['title']} ({$fullClassName})";

                    // If we haven't described this block type yet, describe its fields
                    if (!isset($elementTypeDescriptions[$fullClassName])) {
                        $result .= ": With fields:\n";

                        // Group element fields by type for more efficient presentation
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

                                    // Simplify field type for better LLM comprehension
                                    $simpleType = $this->getShortClassName($fieldType);
                                    $result .= "        - {$relatedField['title']} ({$relatedField['name']}): {$simpleType}{$relatedDescription}";

                                    // Include field options if available
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
                                    // Use full class name for consistency and clarity
                                    $nestedFullClassName = $this->unsanitiseClassName($nestedElementType['class']);
                                    $result .= "      - {$nestedElementType['title']} ({$nestedFullClassName})";

                                    // If this element type has already been fully described elsewhere,
                                    // just reference that it's already described
                                    if (isset($elementTypeDescriptions[$nestedFullClassName])) {
                                        $result .= " (fields described above)\n";
                                    } else {
                                        // If this is a new element type that hasn't been described yet,
                                        // add a reference to see its full description elsewhere
                                        $result .= "\n";
                                    }
                                }
                            }
                        }

                        // Store this element type as processed
                        $elementTypeDescriptions[$fullClassName] = true;
                    } else {
                        // Reference that we've described this block type already, but still show it's available here
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
        
        // Group fields
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
        
        // Ultra-compact standard fields
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
        
        // Hyper-compact elemental areas
        if (!empty($elementalAreas)) {
            $result .= "BLOCKS:\n";
            $blockDefs = []; // Store unique block definitions
            
            foreach ($elementalAreas as $field) {
                $result .= "- {$field['name']}: ";
                $blockTypes = [];
                
                foreach ($field['allowedElementTypes'] as $elementType) {
                    $className = $this->unsanitiseClassName($elementType['class']);
                    $blockTypes[] = $className;
                    
                    // Store block definition if not already stored
                    if (!isset($blockDefs[$className])) {
                        $blockDefs[$className] = $this->formatBlockDefinition($elementType);
                    }
                }
                
                $result .= implode('|', $blockTypes) . "\n";
            }
            
            // Add all block definitions at the end
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
