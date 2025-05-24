<?php

namespace KhalsaJio\ContentCreator\Tests;

use KhalsaJio\ContentCreator\Services\LLMService;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Config\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use ReflectionProperty;

class LLMServiceTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up config for tests
        Config::modify()->set(LLMService::class, 'default_provider', 'OpenAI');
        Config::modify()->set(LLMService::class, 'providers', [
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
            ],
            'Custom' => [
                'class' => LLMServiceMockProvider::class,
                'api_key' => 'test_custom_api_key'
            ]
        ]);
    }

    /**
     * Test setting and getting the provider
     */
    public function testSetGetProvider()
    {
        $service = new LLMService();

        // Default provider should be OpenAI
        $this->assertEquals('OpenAI', $service->getProvider(), 'Default provider should be OpenAI');

        // Set provider to Claude
        $service->setProvider('Claude');
        $this->assertEquals('Claude', $service->getProvider(), 'Provider should be changed to Claude');

        // Test invalid provider
        $this->expectException(\Exception::class);
        $service->setProvider('InvalidProvider');
    }

    /**
     * Test generating content with OpenAI
     */
    public function testGenerateWithOpenAI()
    {
        $mockResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => 'Test generated content from OpenAI'
                    ]
                ]
            ]
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse))
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new LLMService();

        // Inject mock client
        $reflectionProperty = new ReflectionProperty(LLMService::class, 'client');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($service, $mockClient);

        // Generate content
        $content = $service->generateContent('Test prompt');

        $this->assertEquals('Test generated content from OpenAI', $content);
    }

    /**
     * Test generating content with Claude
     */
    public function testGenerateWithClaude()
    {
        $mockResponse = [
            'content' => [
                [
                    'text' => 'Test generated content from Claude'
                ]
            ]
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse))
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new LLMService();
        $service->setProvider('Claude');

        // Inject mock client
        $reflectionProperty = new ReflectionProperty(LLMService::class, 'client');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($service, $mockClient);

        // Generate content
        $content = $service->generateContent('Test prompt');

        $this->assertEquals('Test generated content from Claude', $content);
    }

    /**
     * Test generating content with Custom provider
     */
    public function testGenerateWithCustomProvider()
    {
        $service = new LLMService();
        $service->setProvider('Custom');

        // Generate content
        $content = $service->generateContent('Test prompt');

        $this->assertEquals('Test generated content from custom provider', $content);
    }

    /**
     * Test handling API errors
     */
    public function testHandleApiError()
    {
        $mock = new MockHandler([
            new RequestException('Error communicating with API', new Request('POST', 'test'))
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new LLMService();

        // Inject mock client
        $reflectionProperty = new ReflectionProperty(LLMService::class, 'client');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($service, $mockClient);

        // Generate content should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Error calling OpenAI API');
        $service->generateContent('Test prompt');
    }
}

/**
 * Mock class for testing custom LLM provider
 */
class LLMServiceMockProvider
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function generateContent($prompt, $options = [])
    {
        return 'Test generated content from custom provider';
    }
}
