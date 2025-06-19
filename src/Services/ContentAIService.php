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
            - Relationship fields (has_one, has_many, many_many) should include appropriate related object data.

            For relationship fields:
            - Single related items (has_one): Provide an object with fields relevant to the related class
            - Multiple related items (has_many/many_many): Provide an array of objects with fields relevant to the related class
            - When relationship fields appear within elemental blocks, follow the same pattern
            - Pay attention to relationship descriptions that explain what kind of data is expected

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
    public function formatStructureForPrompt(array $structure): string
    {
        $result = '';

        // Handle different structure formats
        $fields = isset($structure['fields']) ? $structure['fields'] : $structure;

        foreach ($fields as $field) {
            if ($field['type'] === 'ElementalArea') {
                $result .= "- {$field['title']} ({$field['name']}): A content blocks area that can contain the following types of blocks:\n";

                foreach ($field['allowedElementTypes'] as $elementType) {
                    // Unsanitize class name for proper display
                    $className = $this->unsanitiseClassName($elementType['class']);
                    $result .= "  - {$elementType['title']} ({$className}): With fields:\n";

                    foreach ($elementType['fields'] as $elementField) {
                        $description = $elementField['description'] ? " - {$elementField['description']}" : '';

                        // Handle relationship fields within elements
                        if (in_array($elementField['type'], ['has_one', 'has_many', 'many_many'])) {
                            $result .= "    - {$elementField['title']} ({$elementField['name']}) - type: {$elementField['type']}{$description}\n";
                        } else {
                            $result .= "    - {$elementField['title']} ({$elementField['name']}){$description}";

                            // Include field options if available
                            if (isset($elementField['options'])) {
                                $result .= " Options: " . $this->formatOptionsForPrompt($elementField['options']);
                            }

                            $result .= "\n";
                        }
                    }
                }
            } elseif (in_array($field['type'], ['has_one', 'has_many', 'many_many'])) {
                // Format relationship fields specially
                $description = $field['description'] ? " - {$field['description']}" : '';
                $result .= "- {$field['title']} ({$field['name']}) - type: {$field['type']}{$description}\n";
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
