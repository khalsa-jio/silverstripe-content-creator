# AI Nexus Integration

This document explains how to integrate the Content Creator module with the AI Nexus module for LLM (Language Learning Model) services.

## Overview

The Content Creator module can work with different LLM providers:

1. **AI Nexus Module (Recommended)**: Use the [khalsa-jio/silverstripe-ai-nexus](https://github.com/khalsa-jio/silverstripe-ai-nexus) module for streamlined integration with various LLM providers.
2. **Built-in Providers**: The Content Creator module also has basic built-in integrations for some providers.
3. **Custom Implementation**: You can create your own LLM service implementation.

## Using AI Nexus (Recommended)

The AI Nexus module provides a structured and extensible way to connect to various LLM services like OpenAI, Anthropic's Claude, and others.

### Installation

1. Install the AI Nexus module:

    ```bash
    composer require khalsa-jio/silverstripe-ai-nexus
    ```

2. Configure the AI Nexus module with your preferred LLM provider in your project's YAML configuration:

    ```yaml
    # app/_config/ai-nexus.yml
    ---
    Name: ai-nexus-config
    ---
    KhalsaJio\AI\Nexus\LLMClient:
    default_client: 'KhalsaJio\AI\Nexus\Provider\OpenAI'

    KhalsaJio\AI\Nexus\Provider\OpenAI:
    api_key: 'your-openai-key-here'
    model: 'gpt-4o'
    ```

3. Enable AI Nexus in the Content Creator configuration:

```yaml
# app/_config/content-creator.yml
---
Name: content-creator-config
---
KhalsaJio\ContentCreator\Services\LLMService:
  use_ai_nexus: true
```

## Using Built-in Providers

If you prefer not to use the AI Nexus module, the Content Creator module includes basic integrations for some LLM providers.

```yaml
# app/_config/content-creator.yml
---
Name: content-creator-config
---
KhalsaJio\ContentCreator\Services\LLMService:
  use_ai_nexus: false
  default_provider: 'OpenAI'
  providers:
    OpenAI:
      api_key: 'your-openai-key-here'
      api_url: 'https://api.openai.com/v1/chat/completions'
      model: 'gpt-4o'
      max_tokens: 4000
      temperature: 0.7
    Claude:
      api_key: 'your-anthropic-key-here'
      api_url: 'https://api.anthropic.com/v1/messages'
      model: 'claude-3-opus-20240229'
      max_tokens: 4000
      temperature: 0.7
```

## Custom Implementation

For advanced use cases, you can create your own LLM service implementation:

1. Create your custom provider class that implements the required interface.
2. Configure the Content Creator module to use your custom provider:

    ```yaml
    # app/_config/content-creator.yml
    ---
    Name: content-creator-config
    ---
    KhalsaJio\ContentCreator\Services\LLMService:
    use_ai_nexus: false
    default_provider: 'Custom'
    providers:
        Custom:
        class: 'App\Services\MyCustomLLMProvider'
    ```

3. Your custom provider class should implement at minimum a method called `generateContent(string $prompt, array $options = []): string`.

## Troubleshooting

If you encounter issues with the LLM integration:

1. Check that your API keys are correctly configured
2. Verify that the AI Nexus module is properly installed if using that integration
3. Check the SilverStripe logs for any error messages related to the API calls
4. Test your API keys directly with the provider to ensure they are valid

## Extending the Integration

You can extend or customize the integration between Content Creator and AI Nexus by:

1. Creating custom LLM providers in the AI Nexus module
2. Extending the `AINexusAdapter` class to modify how prompts are formatted or responses are parsed
3. Adding your own preprocessing or postprocessing logic to the content generation process

## API Reference

For more details on how to use and extend the AI Nexus module, please refer to the [AI Nexus documentation](https://github.com/khalsa-jio/silverstripe-ai-nexus).
