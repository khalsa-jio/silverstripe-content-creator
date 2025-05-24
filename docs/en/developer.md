# Content Creator Developer Documentation

## Architecture

The Content Creator module consists of several key components:

1. **LLMService**: A service class that handles communication with various Language Learning Model (LLM) providers such as OpenAI and Anthropic/Claude.

2. **ContentGeneratorService**: A service that analyzes the structure of pages (fields and elemental blocks) and generates appropriate content using LLMs.

3. **ContentCreatorExtension**: A DataExtension applied to SiteTree that adds the content generation UI to the CMS.

4. **ContentCreatorController**: A controller that handles AJAX requests from the UI.

5. **React Components**: React components for the content generation UI, including the modal dialog and content preview.

## Configuration

### YAML Configuration

The module can be configured in your project's YAML config files. Here's an example configuration:

```yaml
# Basic configuration
KhalsaJio\ContentCreator\Services\LLMService:
  default_provider: 'OpenAI'  # Options: 'OpenAI', 'Claude', 'Custom'
  providers:
    OpenAI:
      api_key: 'your-api-key-here' # Replace with actual key or use Injector (see Environment Variables section)
      model: 'gpt-4o'
      max_tokens: 4000
      temperature: 0.7
    Claude:
      api_key: 'your-anthropic-key-here'
      model: 'claude-3-opus-20240229'
      max_tokens: 4000
      temperature: 0.7
    Custom:
      class: 'Your\Custom\LLMProvider'
      api_key: 'your-custom-api-key-here'

# For environment variable injection, you'll need Injector config (see Environment Variables section below)
SilverStripe\Core\Injector\Injector:
  KhalsaJio\ContentCreator\Services\LLMService:
    properties:
      providers:
        OpenAI:
          api_key: '`OPENAI_API_KEY`'
        Claude:
          api_key: '`ANTHROPIC_API_KEY`'
        Custom:
          api_key: '`CUSTOM_LLM_API_KEY`'

KhalsaJio\ContentCreator\Extensions\ContentCreatorExtension:
  enabled_page_types:
    - SilverStripe\CMS\Model\SiteTree
  excluded_page_types:
    - SilverStripe\ErrorPage\ErrorPage
```

### Environment Variables

For security reasons, it's recommended to store API keys as environment variables.
You can reference environment variables in SilverStripe YAML configuration using the appropriate Injector pattern:

```yaml
# Your _config/content-creator.yml file
---
Name: content-creator-config
---
KhalsaJio\ContentCreator\Services\LLMService:
  providers:
    OpenAI:
      api_key: 'openai-api-key'
    Claude:
      api_key: 'anthropic-claude-key'

# Enable environment variable substitution
---
Name: content-creator-environment
After:
  - '#content-creator-config'
Only:
  environment: dev
---
SilverStripe\Core\Injector\Injector:
  KhalsaJio\ContentCreator\Services\LLMService:
    properties:
      providers:
        OpenAI:
          api_key: '`OPENAI_API_KEY`'
        Claude:
          api_key: '`ANTHROPIC_API_KEY`'
```

This configuration uses placeholder values in the main configuration, and then uses SilverStripe's environment variable injection syntax with backticks (`OPENAI_API_KEY`) in the Injector section. The backtick syntax is only used in the environment-specific section through the Injector, ensuring your API keys remain secure across different environments.

Then in your `.env` file:

```bash
OPENAI_API_KEY="your-openai-key-here"
ANTHROPIC_API_KEY="your-anthropic-key-here"
```

## Extending the Module

You can extend the functionality of the Content Creator module by creating custom LLM providers or adding support for additional field types.

### Custom LLM Provider

You can create your own LLM provider by creating a class that implements the necessary methods:

```php
namespace Your\Custom;

class LLMProvider
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function generateContent($prompt, $options = [])
    {
        // Your implementation here
        // Should return a string with the generated content
    }
}
```

Then configure it in your YAML:

```yaml
KhalsaJio\ContentCreator\Services\LLMService:
  default_provider: 'Custom'
  providers:
    Custom:
      class: 'Your\Custom\LLMProvider'
      api_key: 'your-custom-api-key'
      # Add any other config options your provider needs

# Or in your Injector configuration:
SilverStripe\Core\Injector\Injector:
  KhalsaJio\ContentCreator\Services\LLMService:
    properties:
      providers:
        Custom:
          api_key: '`YOUR_CUSTOM_API_KEY`'
```

### Using AI Nexus

The module can be configured to use the AI Nexus module for more advanced LLM integration. This requires the `khalsa-jio/silverstripe-ai-nexus` package to be installed separately:

```bash
composer require khalsa-jio/silverstripe-ai-nexus
```

After installing the package, enable the integration in your YAML configuration:

```yaml
KhalsaJio\ContentCreator\Services\LLMService:
  use_ai_nexus: true
```

If the AI Nexus package is not installed and you try to use this feature, the system will throw an exception with a helpful error message.

### Adding Support for Additional Field Types

By default, the ContentGeneratorService recognizes standard field types like TextField, TextareaField, and HTMLEditorField as content fields. To add support for additional field types, you can create a custom extension for the ContentGeneratorService.

```php
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FormField;
use Your\Custom\Field;

class ContentGeneratorServiceExtension extends Extension
{
    public function updateContentFieldTypes(&$contentFieldTypes)
    {
        // Add custom field types to the content field types array
        $contentFieldTypes[] = Field::class;
    }
}
```

And apply the extension in your YAML:

```yaml
KhalsaJio\ContentCreator\Services\ContentGeneratorService:
  extensions:
    - Your\Extension\ContentGeneratorServiceExtension
```

## Testing

The module includes both PHP unit tests and JavaScript tests.

To run PHP tests:

```bash
vendor/bin/phpunit vendor/khalsa-jio/silverstripe-content-creator/tests/php/
```

To run JavaScript tests:

```bash
cd vendor/khalsa-jio/silverstripe-content-creator
yarn test
```

### Debug Logging

You can enable debug logging to help troubleshoot issues:

```yaml
SilverStripe\Core\Injector\Injector:
  Psr\Log\LoggerInterface.content-creator:
    class: Monolog\Logger
    constructor:
      - 'content-creator'
    calls:
      LogFileHandler: [ pushHandler, [ %$LogFileHandler ] ]
  LogFileHandler:
    class: Monolog\Handler\StreamHandler
    constructor:
      - '../content-creator.log'
      - 'debug'
```

Then inject the logger into the services:

```yaml
SilverStripe\Core\Injector\Injector:
  KhalsaJio\ContentCreator\Services\LLMService:
    properties:
      logger: %$Psr\Log\LoggerInterface.content-creator
```

### Common Issues

1. **Modal doesn't appear**: Make sure you have included the JavaScript and CSS in your project, and that the modal container is being rendered in the CMS.

2. **API errors**: Check your API keys and ensure they are correctly set in your environment variables.

3. **Content not applying**: If content is not applying correctly, check that the field names match between the generated content and the page fields.

## API Reference

See [API Documentation](api.md) for detailed information on the module's classes and methods.
