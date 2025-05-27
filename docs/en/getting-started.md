# Getting Started

This guide will help you quickly get started with the Silverstripe Content Creator module.

## 1. Install the module

```bash
composer require khalsa-jio/silverstripe-content-creator
```

## 2. Configure your LLM provider

Create a YAML configuration file (e.g., `app/_config/content-creator.yml`):

```yaml
---
Name: app-content-creator
After: 
  - '#content-creator'
---

SilverStripe\Core\Injector\Injector:
    KhalsaJio\ContentCreator\Services\LLMService:
        default_provider: 'OpenAI'
        providers:
            OpenAI:
                api_key: '`OPENAI_API_KEY`'
                model: 'gpt-4o'
```

## 3. Set your API key

Add your API key to your environment (`.env` file):

```bash
OPENAI_API_KEY="your-api-key-here"
```

## 4. Run a dev/build

```bash
vendor/bin/sake dev/build flush=1
```

## 5. Start creating content

1. Go to the CMS
2. Navigate to the page you want to edit.
3. Look for the "AI Content" button (rocket icon)
4. Click the button to open the Content Creator modal.
5. Enter a prompt describing the content you want to create
6. Review and apply the generated content

## Next Steps

- Check the [User Guide](userguide.md) for tips on writing effective prompts
- Read the [Developer Documentation](developer.md) to learn how to extend the module
- See the [API Reference](api.md) for detailed information on the module's classes and methods
