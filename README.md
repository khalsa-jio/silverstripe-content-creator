# Silverstripe Content Creator

An AI-powered content generation module for Silverstripe CMS that allows content editors to create page content using natural language prompts. When creating a new page in the Silverstripe CMS, this module adds a button that opens a modal where users can input prompts to generate content. The AI will structure the content based on the available fields or Elemental blocks for that page type.

![Silverstripe Content Creator Screenshot](docs/en/images/content-creator-screenshot.png)

## How it works

1. The module adds a "AI Content" button to the GridField Item request in the Silverstripe CMS.
2. When clicked, a modal opens where users can enter a prompt describing the content they want to generate.
3. The system analyzes the page structure (fields and elemental blocks if available).
4. The LLM (Language Learning Model) generates appropriate content based on the prompt and page structure.
5. Users can review the generated content in a preview.
6. Once approved, the content is applied to the page fields using the Silverstripe Populate module.
7. Users can continue the conversation to refine the content as needed.

## Features

- AI-powered content generation directly within the Silverstripe CMS page editing interface
- Intelligent content structuring based on available page fields or Elemental blocks
- Chat-like interface for iterating on generated content
- Configurable LLM provider integration
- Support for integration with various LLM providers (OpenAI, Anthropic, etc.)
- Compatibility with the Silverstripe Populate module for filling content
- Comprehensive unit and end-to-end tests

## Requirements

- PHP 8.1+
- Silverstripe CMS 5.0+
- Silverstripe Admin 2.0+
- Silverstripe Populate 3.0+
- GuzzleHTTP 7.0+

## Installation

```sh
composer require khalsa-jio/silverstripe-content-creator
```

After installation, run a dev/build to ensure all database tables and extensions are properly set up.

## Configuration

You can configure the module via YAML configuration. Create a file in your project's `_config` directory (e.g., `content-creator.yml`) with the following settings:

```yaml
---
Name: my-content-creator-config
After:
  - '#content-creator'
---

KhalsaJio\AI\Nexus\LLMClient:
  default_client: KhalsaJio\AI\Nexus\Provider\OpenAI

SilverStripe\Core\Injector\Injector:
  KhalsaJio\AI\Nexus\Provider\OpenAI:
    properties:
      ApiKey: '`OPENAI_API_KEY`'
      Model: 'gpt-4o'

KhalsaJio\ContentCreator\Extensions\ContentCreatorExtension:
  enabled_page_types:
    - SilverStripe\CMS\Model\SiteTree
    # Add specific page classes if you want to limit to certain types
  excluded_page_types:
    - SilverStripe\ErrorPage\ErrorPage
    # Add page types that should never show the content creator button
```

## Documentation

- [User Guide](docs/en/userguide.md)
- [Developer Documentation](docs/en/developer.md)
- [Caching](docs/en/caching.md)

## License

See [License](LICENSE) for details.
