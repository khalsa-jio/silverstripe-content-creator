<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Basic unit tests for LLM functionality
 */
class SimpleUnitTest extends TestCase
{
    public function testProviderConfig()
    {
        // Simple test to verify we can run tests
        $providers = [
            'OpenAI' => [
                'api_key' => 'test_api_key',
                'model' => 'gpt-4o',
                'max_tokens' => 1000,
                'temperature' => 0.7,
            ],
            'Claude' => [
                'api_key' => 'test_claude_api_key',
                'model' => 'claude-3-opus-20240229',
                'max_tokens' => 1000,
                'temperature' => 0.7,
            ]
        ];

        // Test that we can access provider configuration
        $this->assertEquals('test_api_key', $providers['OpenAI']['api_key']);
        $this->assertEquals('gpt-4o', $providers['OpenAI']['model']);
        $this->assertEquals('claude-3-opus-20240229', $providers['Claude']['model']);
    }

    public function testModelTokenLimit()
    {
        // Mock the token calculation function
        $tokenCount = function($text) {
            // Approximate token count (1 token ~= 4 chars)
            return ceil(strlen($text) / 4);
        };

        $shortText = "This is a short prompt.";
        $longText = str_repeat("This is a very long input that would exceed token limits. ", 100);

        // Test token counting
        $this->assertLessThan(20, $tokenCount($shortText));
        $this->assertGreaterThan(500, $tokenCount($longText));

        // Test token truncation logic
        $maxTokens = 100;
        $truncatedText = substr($longText, 0, $maxTokens * 4); // Approximate truncation

        $this->assertLessThanOrEqual($maxTokens, $tokenCount($truncatedText));
    }
}
