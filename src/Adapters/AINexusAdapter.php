<?php

namespace KhalsaJio\ContentCreator\Adapters;

use Exception;

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
     * The LLMClient class name
     */
    protected const LLM_CLIENT_CLASS = 'KhalsaJio\\AI\\Nexus\\LLMClient';

    /**
     * Constructor
     */
    public function __construct()
    {
        if (!class_exists(self::LLM_CLIENT_CLASS)) {
            throw new Exception('AI Nexus module not installed. Please install khalsa-jio/silverstripe-ai-nexus package.');
        }
        
        $clientClass = self::LLM_CLIENT_CLASS;
        $this->client = $clientClass::inst();
    }

    /**
     * Generate content using the AI Nexus client
     *
     * @param string $prompt
     * @param array $options
     * @return string
     * @throws Exception
     */
    public function generateContent(string $prompt, array $options = []): string
    {
        $clientClass = self::LLM_CLIENT_CLASS;
        $providerType = $clientClass::getDefaultClient();

        switch ($providerType) {
            case 'KhalsaJio\\AI\\Nexus\\Provider\\OpenAI':
            case 'OpenAI':
                return $this->generateWithOpenAI($prompt, $options);

            case 'KhalsaJio\\AI\\Nexus\\Provider\\Claude':
            case 'Claude':
                return $this->generateWithClaude($prompt, $options);

            default:
                try {
                    $response = $this->client->chat([
                        'prompt' => $prompt,
                        'options' => $options
                    ]);

                    if (isset($response['content'])) {
                        return $response['content'];
                    }

                    throw new Exception("Invalid response from AI Nexus");
                } catch (Exception $e) {
                    throw new Exception("Error using AI Nexus: " . $e->getMessage());
                }
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
            $payload = [
                'model' => $options['model'] ?? 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful content creation assistant.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => $options['max_tokens'] ?? 4000,
                'temperature' => $options['temperature'] ?? 0.7,
            ];

            $response = $this->client->chat($payload, 'responses');

            if (isset($response['choices'])) {
                return $response['choices'];
            }

            throw new Exception("Invalid response from OpenAI via AI Nexus");
        } catch (Exception $e) {
            throw new Exception("Error using OpenAI via AI Nexus: " . $e->getMessage());
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
            $payload = [
                'model' => $options['model'] ?? 'claude-3-opus-20240229',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => $options['max_tokens'] ?? 4000,
                'temperature' => $options['temperature'] ?? 0.7,
            ];

            $response = $this->client->chat($payload, 'messages');

            if (isset($response['content'])) {
                return $response['content'];
            }

            throw new Exception("Invalid response from Claude via AI Nexus");
        } catch (Exception $e) {
            throw new Exception("Error using Claude via AI Nexus: " . $e->getMessage());
        }
    }
}
