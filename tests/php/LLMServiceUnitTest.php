<?php

namespace KhalsaJio\ContentCreator\Tests;

use PHPUnit\Framework\TestCase;
use KhalsaJio\ContentCreator\Services\LLMService;

/**
 * Basic unit tests for LLMService with mocked dependencies
 */
class LLMServiceUnitTest extends TestCase
{
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock class extending LLMService to avoid Config issues
        $mockLLMService = new class extends LLMService {
            private $mockProviders = [];
            private $mockDefaultProvider = '';
            private $mockCurrentProvider = '';

            public function __construct() {
                //
            }

            public function setMockProviders(array $providers) {
                $this->mockProviders = $providers;
            }

            public function setMockDefaultProvider(string $provider) {
                $this->mockDefaultProvider = $provider;
            }

            public function setMockCurrentProvider(string $provider) {
                $this->mockCurrentProvider = $provider;
            }

            public function getProvider() {
                return $this->mockCurrentProvider;
            }

            public function setProvider($provider): static {
                $this->mockCurrentProvider = $provider;
                return $this;
            }

            public function getProviderConfig() {
                return $this->mockProviders[$this->mockCurrentProvider] ?? null;
            }
        };

        $this->service = $mockLLMService;

        // Set up test data
        $this->service->setMockProviders([
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
        ]);

        // Set default provider
        $reflectionProp = new \ReflectionProperty(LLMService::class, 'default_provider');
        $reflectionProp->setAccessible(true);
        $reflectionProp->setValue($this->service, 'OpenAI');

        // Set currentProvider
        $reflectionProp = new \ReflectionProperty(LLMService::class, 'currentProvider');
        $reflectionProp->setAccessible(true);
        $reflectionProp->setValue($this->service, 'OpenAI');
    }

    public function testGetSetProvider()
    {
        // Test getting default provider
        $reflectionMethod = new \ReflectionMethod(LLMService::class, 'getProvider');
        $reflectionMethod->setAccessible(true);
        $this->assertEquals('OpenAI', $reflectionMethod->invoke($this->service));

        // Test setting provider
        $reflectionMethod = new \ReflectionMethod(LLMService::class, 'setProvider');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($this->service, 'Claude');

        // Test getting new provider
        $reflectionMethod = new \ReflectionMethod(LLMService::class, 'getProvider');
        $reflectionMethod->setAccessible(true);
        $this->assertEquals('Claude', $reflectionMethod->invoke($this->service));
    }

    public function testGetProviderConfig()
    {
        // Set up reflection for getProviderConfig
        $reflectionMethod = new \ReflectionMethod(LLMService::class, 'getProviderConfig');
        $reflectionMethod->setAccessible(true);

        // Test getting config for default provider (OpenAI)
        $config = $reflectionMethod->invoke($this->service);
        $this->assertEquals('test_api_key', $config['api_key']);
        $this->assertEquals('gpt-4o', $config['model']);

        // Set different provider and test config
        $reflectionProp = new \ReflectionProperty(LLMService::class, 'currentProvider');
        $reflectionProp->setAccessible(true);
        $reflectionProp->setValue($this->service, 'Claude');

        $config = $reflectionMethod->invoke($this->service);
        $this->assertEquals('test_claude_api_key', $config['api_key']);
        $this->assertEquals('claude-3-opus-20240229', $config['model']);
    }
}
