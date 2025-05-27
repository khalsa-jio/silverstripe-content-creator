<?php

namespace KhalsaJio\ContentCreator\Adapters;

use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * Adapter class to integrate with AI Nexus module
 *
 * This class requires the KhalsaJio\AI\Nexus package to be installed
 * @see https://github.com/khalsa-jio/silverstripe-ai-nexus
 */
class AINexusAdapter
{
    /**
     * The AI Nexus client
     *
     * @var object|null
     */
    protected $client;

    /**
     * Logger instance
     *
     * @var \Psr\Log\LoggerInterface|null
     */
    protected $logger;

    /**
     * The LLMClient class name
     */
    protected const LLM_CLIENT_CLASS = 'KhalsaJio\\AI\\Nexus\\LLMClient';

    /**
     * Default timeout for AI requests in seconds.
     */
    protected const DEFAULT_REQUEST_TIMEOUT = 60;

    /**
     * @var array
     */
    private static $fallback_model_map = [
        'OpenAI' => 'gpt-4o',
        'Claude' => 'claude-3-opus-20240229',
        'default' => 'gpt-4o'
    ];

    /**
     * Constructor
     *
     * @throws Exception if AI Nexus module is not installed or client initialization fails
     */
    public function __construct()
    {
        // Initialize logger if available
        if (class_exists('Psr\\Log\\LoggerInterface')) {
            $this->logger = \SilverStripe\Core\Injector\Injector::inst()->get('Psr\\Log\\LoggerInterface');
        }

        if (!class_exists(self::LLM_CLIENT_CLASS)) {
            throw new Exception('AI Nexus module not installed. Please install khalsa-jio/silverstripe-ai-nexus package.');
        }

        try {
            $clientClass = self::LLM_CLIENT_CLASS;
            $this->client = $clientClass::singleton();

            if (!$this->client->getLLMClient()) {
                throw new Exception('No active LLM client configured in AI Nexus');
            }

            if (!$this->client->validate()) {
                throw new Exception('AI Nexus client validation failed. Check your configuration.');
            }
        } catch (Exception $e) {
            throw new Exception('Failed to initialize AI Nexus client: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate content using the AI Nexus client
     *
     * @param string $prompt The prompt to send to the LLM
     * @param array $options Additional options for the LLM request
     * @return string The generated content
     * @throws Exception If there's an error during generation
     */
    public function generateContent(string $prompt, array $options = []): string
    {
        try {
            // Get the client name to determine how to format the request
            $clientName = $this->client->getClientName();

            // Normalize client name for comparison
            $clientNameLower = strtolower($clientName);

            if (strpos($clientNameLower, 'openai') !== false) {
                return $this->generateWithOpenAI($prompt, $options);
            } elseif (strpos($clientNameLower, 'claude') !== false || strpos($clientNameLower, 'anthropic') !== false) {
                return $this->generateWithClaude($prompt, $options);
            } else {
                $this->logDebug("Using generic client handler for: $clientName");

                // Prepare a generic payload structure
                $payload = [
                    'model' => $options['model'] ?? self::$fallback_model_map['default'],
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'timeout' => $options['timeout'] ?? self::DEFAULT_REQUEST_TIMEOUT,
                ];

                // Add optional parameters if provided
                if (isset($options['max_tokens'])) {
                    $payload['max_tokens'] = $options['max_tokens'];
                }

                if (isset($options['temperature'])) {
                    $payload['temperature'] = $options['temperature'];
                }

                $endpoint = $options['endpoint'] ?? 'responses';

                $response = null;
                try {
                    $response = $this->client->chat($payload, $endpoint);
                } catch (ConnectException $e) {
                    throw new Exception("AI request failed due to a connection timeout. Please try again later.", 0, $e);
                } catch (RequestException $e) {
                    $errorMessage = "AI Nexus (generic) - Request Error: " . $e->getMessage();
                    if ($e->hasResponse()) {
                        $errorMessage .= " | Status: " . $e->getResponse()->getStatusCode() . " | Body: " . (string) $e->getResponse()->getBody();
                    }
                    $this->logError($errorMessage);
                    throw new Exception("AI request failed: " . $e->getMessage(), 0, $e);
                }

                // Try to extract content from different possible response formats
                if (isset($response['content'])) {
                    return $response['content'];
                } elseif (isset($response['choices'][0]['message']['content'])) {
                    return $response['choices'][0]['message']['content'];
                } elseif (isset($response['message']['content'])) {
                    return $response['message']['content'];
                } elseif (isset($response['completion'])) {
                    return $response['completion'];
                } elseif (isset($response['choices']) && is_string($response['choices'])) {
                    return $response['choices'];
                }

                throw new Exception("Invalid response format from AI Nexus client: $clientName");
            }
        } catch (Exception $e) {
            if (!($e instanceof ConnectException || $e instanceof RequestException)) {
                $this->logError("Error using AI Nexus (generateContent): " . $e->getMessage());
            }

            throw new Exception("Error generating content via AI Nexus: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Log a debug message
     *
     * @param string $message
     */
    protected function logDebug(string $message): void
    {
        if ($this->logger) {
            $this->logger->debug($message);
        }
    }

    /**
     * Log a warning message
     *
     * @param string $message
     */
    protected function logWarning(string $message): void
    {
        if ($this->logger) {
            $this->logger->warning($message);
        }
    }

    /**
     * Log an error message
     *
     * @param string $message
     */
    protected function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->error($message);
        }
    }

    /**
     * Generate content using OpenAI via AI Nexus
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function generateWithOpenAI(string $prompt, array $options = []): string
    {
        try {
            $model = $options['model'] ?? self::$fallback_model_map['OpenAI'];

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful content creation assistant.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => $options['max_tokens'] ?? 4000,
                'temperature' => $options['temperature'] ?? 0.7,
                'timeout' => $options['timeout'] ?? self::DEFAULT_REQUEST_TIMEOUT,
            ];

            $response = null;
            try {
                $response = $this->client->chat($payload, 'responses');
            } catch (ConnectException $e) {
                throw new Exception("OpenAI request failed due to a connection timeout. Please try again later.", 0, $e);
            } catch (RequestException $e) {
                $errorMessage = "OpenAI via AI Nexus - Request Error: " . $e->getMessage();
                if ($e->hasResponse()) {
                    $errorMessage .= " | Status: " . $e->getResponse()->getStatusCode() . " | Body: " . (string) $e->getResponse()->getBody();
                }
                $this->logError($errorMessage);
                throw new Exception("OpenAI request failed: " . $e->getMessage(), 0, $e);
            }

            // Handle OpenAI response format
            if (isset($response['choices'][0]['message']['content'])) {
                return $response['choices'][0]['message']['content'];
            } elseif (isset($response['content'])) {
                // Alternative response format
                return $response['content'];
            } elseif (isset($response['choices']) && is_string($response['choices'])) {
                // Legacy format
                return $response['choices'];
            }

            throw new Exception("Invalid response format from OpenAI via AI Nexus");
        } catch (Exception $e) {
            if (!($e instanceof ConnectException || $e instanceof RequestException)) {
                $this->logError("Error using OpenAI via AI Nexus (outer catch): " . $e->getMessage());
            }
            throw new Exception("Error processing OpenAI request via AI Nexus: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate content using Claude via AI Nexus
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function generateWithClaude(string $prompt, array $options = []): string
    {
        try {
            $model = $options['model'] ?? self::$fallback_model_map['Claude'];

            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => $options['max_tokens'] ?? 4000,
                'temperature' => $options['temperature'] ?? 0.7,
            ];

            $response = null;
            try {
                $response = $this->client->chat($payload, 'messages');
            } catch (ConnectException $e) {
                $this->logError("Claude via AI Nexus - Connection Timeout: " . $e->getMessage());
                throw new Exception("Claude request failed due to a connection timeout. Please try again later.", 0, $e);
            } catch (RequestException $e) {
                $errorMessage = "Claude via AI Nexus - Request Error: " . $e->getMessage();
                if ($e->hasResponse()) {
                    $errorMessage .= " | Status: " . $e->getResponse()->getStatusCode() . " | Body: " . (string) $e->getResponse()->getBody();
                }
                $this->logError($errorMessage);
                throw new Exception("Claude request failed: " . $e->getMessage(), 0, $e);
            }

            // Handle various Claude response formats
            if (isset($response['content'])) {
                return $response['content'];
            }

            $this->logWarning("Invalid response format from Claude via AI Nexus. Raw response: " . json_encode($response));
            throw new Exception("Invalid response format from Claude via AI Nexus");
        } catch (Exception $e) {
            // This will catch the "Invalid response format" or the re-thrown Guzzle exceptions
            if (!($e instanceof ConnectException || $e instanceof RequestException)) {
                $this->logError("Error using Claude via AI Nexus (outer catch): " . $e->getMessage());
            }
            throw new Exception("Error processing Claude request via AI Nexus: " . $e->getMessage(), 0, $e);
        }
    }
}
