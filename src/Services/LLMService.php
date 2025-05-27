<?php

namespace KhalsaJio\ContentCreator\Services;

use Exception;
use GuzzleHttp\Client;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use GuzzleHttp\Exception\RequestException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Service for interacting with Language Learning Models (LLMs)
 */
class LLMService
{
    use Injectable;
    use Configurable;

    /**
     * Default LLM provider
     *
     * @config
     * @var string
     */
    private static $default_provider = 'OpenAI';

    /**
     * Configuration for different LLM providers
     *
     * @config
     * @var array
     */
    private static $providers = [];

    /**
     * @var string
     */
    private $currentProvider;

    /**
     * @var array
     */
    private $providerConfig;

    /**
     * Whether to use the AI Nexus module if available
     *
     * @config
     * @var bool
     */
    private static $use_ai_nexus = true;

    /**
     * AI Nexus adapter 
     * 
     * @var \KhalsaJio\ContentCreator\Adapters\AINexusAdapter
     */
    private $aiNexusAdapter = null;

    /**
     * The HTTP client
     *
     * @var Client
     */
    private $client;

    /**
     * Initialize the LLM service
     */
    public function __construct()
    {
        // Try to use AI Nexus if configured to do so
        if ($this->config()->get('use_ai_nexus')) {
            try {
                // Check if AI Nexus module is available
                if (class_exists('KhalsaJio\\AI\\Nexus\\LLMClient')) {
                    $this->aiNexusAdapter = Injector::inst()->get('KhalsaJio\\ContentCreator\\Adapters\\AINexusAdapter');
                    return; // Successfully initialized with AI Nexus
                }
            } catch (Exception $e) {
                // Fallback to default provider if AI Nexus fails
                // Log error but continue with fallback
                if (class_exists('Psr\\Log\\LoggerInterface')) {
                    $logger = Injector::inst()->get('Psr\\Log\\LoggerInterface');
                    $logger->warning('Failed to initialize AI Nexus: ' . $e->getMessage() . '. Falling back to default provider.');
                }
            }
        }

        // Fallback to direct integration
        $this->client = new Client();
        $this->setProvider($this->config()->get('default_provider'));
    }

    /**
     * Set the LLM provider
     *
     * @param string $provider
     * @return $this
     * @throws Exception
     */
    public function setProvider(string $provider)
    {
        $providers = $this->config()->get('providers');

        if (!isset($providers[$provider])) {
            throw new Exception("LLM provider '$provider' is not configured");
        }

        $this->currentProvider = $provider;
        $this->providerConfig = $providers[$provider];

        return $this;
    }

    /**
     * Get the current LLM provider
     *
     * @return string
     */
    public function getProvider()
    {
        return $this->currentProvider;
    }

    /**
     * Generate content using the LLM
     *
     * @param string $prompt
     * @param array $options Additional provider-specific options
     * @return string The generated content
     * @throws Exception
     */
    public function generateContent(string $prompt, array $options = [])
    {
        // Check if AI Nexus is enabled
        if ($this->config()->get('use_ai_nexus')) {
            try {
                return $this->generateWithAINexus($prompt, $options);
            } catch (Exception $e) {
                // If AI Nexus fails, fall back to direct providers
                // but only if the error is about the missing package
                if (strpos($e->getMessage(), 'AI Nexus module not installed') === false) {
                    throw $e;
                }
                // Otherwise continue with fallback
            }
        }

        // Use the appropriate provider's API
        switch ($this->currentProvider) {
            case 'OpenAI':
                return $this->generateWithOpenAI($prompt, $options);

            case 'Claude':
                return $this->generateWithClaude($prompt, $options);

            case 'Custom':
                return $this->generateWithCustomProvider($prompt, $options);

            default:
                throw new Exception("Unknown LLM provider: {$this->currentProvider}");
        }
    }

    /**
     * Generate content using OpenAI's API
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function generateWithOpenAI(string $prompt, array $options = [])
    {
        try {
            $apiKey = $this->providerConfig['api_key'];
            $model = $options['model'] ?? $this->providerConfig['model'] ?? 'gpt-4o';
            $maxTokens = $options['max_tokens'] ?? $this->providerConfig['max_tokens'] ?? 4000;
            $temperature = $options['temperature'] ?? $this->providerConfig['temperature'] ?? 0.7;

            $response = $this->client->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => "Bearer $apiKey",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a helpful content creation assistant.'],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['choices'][0]['message']['content'])) {
                return $result['choices'][0]['message']['content'];
            }

            throw new Exception("Invalid response from OpenAI API");

        } catch (RequestException $e) {
            throw new Exception("Error calling OpenAI API: " . $e->getMessage());
        }
    }

    /**
     * Generate content using Claude/Anthropic's API
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function generateWithClaude(string $prompt, array $options = [])
    {
        try {
            $apiKey = $this->providerConfig['api_key'];
            $model = $options['model'] ?? $this->providerConfig['model'] ?? 'claude-3-opus-20240229';
            $maxTokens = $options['max_tokens'] ?? $this->providerConfig['max_tokens'] ?? 4000;
            $temperature = $options['temperature'] ?? $this->providerConfig['temperature'] ?? 0.7;

            $response = $this->client->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature,
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['content'][0]['text'])) {
                return $result['content'][0]['text'];
            }

            throw new Exception("Invalid response from Claude API");

        } catch (RequestException $e) {
            throw new Exception("Error calling Claude API: " . $e->getMessage());
        }
    }

    /**
     * Generate content using a custom provider
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function generateWithCustomProvider(string $prompt, array $options = [])
    {
        if (!isset($this->providerConfig['class']) || !class_exists($this->providerConfig['class'])) {
            throw new Exception("Custom LLM provider class not configured or doesn't exist");
        }

        $providerClass = $this->providerConfig['class'];
        $provider = new $providerClass($this->providerConfig);

        if (!method_exists($provider, 'generateContent')) {
            throw new Exception("Custom LLM provider must implement 'generateContent' method");
        }

        return $provider->generateContent($prompt, $options);
    }

    /**
     * Generate content using the AI Nexus module
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws Exception
     */
    protected function generateWithAINexus(string $prompt, array $options = [])
    {
        try {
            // Use the cached adapter if available
            if (!$this->aiNexusAdapter) {
                $this->aiNexusAdapter = Injector::inst()->get('KhalsaJio\\ContentCreator\\Adapters\\AINexusAdapter');
            }

            // Merge provider config with options
            $mergedOptions = array_merge(
                (array)($this->providerConfig ?? []),
                $options
            );

            return $this->aiNexusAdapter->generateContent($prompt, $mergedOptions);

        } catch (Exception $e) {
            throw new Exception("Error using AI Nexus: " . $e->getMessage(), 0, $e);
        }
    }
}
