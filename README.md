# Silverstripe Content Creator

An AI-powered content generation module for Silverstripe CMS that allows content editors to create page content using natural language prompts. When creating a new page in the Silverstripe CMS, this module adds a button that opens a modal where users can input prompts to generate content. The AI will structure the content based on the available fields or Elemental blocks for that page type.

## Key Features

- AI-powered content generation directly within the Silverstripe CMS page editing interface
- Intelligent content structuring based on available page fields or Elemental blocks
- Support for nested ElementalAreas (blocks within blocks) with cycle detection
- Chat-like interface for iterating on generated content
- Streaming responses for better user experience
- Clear presentation of field types and descriptions in prompts
- Configurable field exclusions and inclusions
- Developer tools for debugging and optimizing prompts

## Architecture Overview

The module uses a service-oriented architecture with specialized services that work together to provide content generation capabilities.

### Core Services

1. **ContentStructureService**
   - Analyzes DataObject structure, fields, and relationships
   - Determines which fields are eligible for content generation
   - Handles relationship configuration and field metadata
   - Implements cycle detection for nested ElementalAreas

2. **ContentAIService**
   - Handles communication with AI models via LLMClient
   - Builds prompts based on DataObject structure
   - Parses generated content from various formats
   - Handles streaming and non-streaming content generation

3. **ContentPopulatorService**
   - Populates DataObjects with generated content
   - Handles different field types appropriately
   - Manages transactions for content application
   - Supports nested ElementalAreas and relationship fields

4. **ContentCacheService**
   - Caches page structure and other data to improve performance

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

## Usage of Services

### Basic Usage

```php
use KhalsaJio\ContentCreator\Services\ContentStructureService;
use KhalsaJio\ContentCreator\Services\ContentAIService;
use KhalsaJio\ContentCreator\Services\ContentPopulatorService;
use SilverStripe\Core\Injector\Injector;

class MyController extends Controller
{
    public function generateContent($pageID, $prompt)
    {
        // Get the page to generate content for
        $page = Page::get()->byID($pageID);
        
        // Get the AI service
        $aiService = Injector::inst()->get(ContentAIService::class);
        
        // Get the populator service
        $populatorService = Injector::inst()->get(ContentPopulatorService::class);
        
        // Generate content
        $generatedContent = $aiService->generateContent($page, $prompt);
        
        // Populate the page with the generated content
        $populatedPage = $populatorService->populateContent($page, $generatedContent);
        
        return $populatedPage;
    }
}
```

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

## License

See [License](LICENSE) for details.
