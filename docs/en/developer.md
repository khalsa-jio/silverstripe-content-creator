# Content Creator Developer Documentation

## Architecture

The Content Creator module consists of several key components:

1. **ContentGeneratorService**: A service that analyzes the structure of pages (fields and elemental blocks) and generates appropriate content using LLMs.

2. **ContentCreatorExtension**: An Extension that can be applied to DataObject and will add the content generation UI to the CMS.

3. **ContentCreatorController**: A controller that handles requests from the UI.

4. **React Components**: React components for the content generation UI, including the modal dialog and content preview.

5. **LLMClient**: This is used to interacts with various LLM providers (OpenAI, Anthropic, etc.) to generate content based on user prompts.

## Configuration

### YAML Configuration

The module can be configured in your project's YAML config files. Here's an example configuration:

```yaml

KhalsaJio\ContentCreator\Extensions\ContentCreatorExtension:
  enabled_page_types:
    - SilverStripe\CMS\Model\SiteTree
  excluded_page_types:
    - SilverStripe\ErrorPage\ErrorPage
```

### Environment Variables

For security reasons, it's recommended to store API keys as environment variables.
You can reference environment variables in SilverStripe YAML configuration using the appropriate Injector pattern, to setup your LLM providers, follow AI Nexus integration[https://github.com/khalsa-jio/silverstripe-ai-nexus]

Then in your `.env` file:

```bash
OPENAI_API_KEY="your-openai-key-here"
ANTHROPIC_API_KEY="your-anthropic-key-here"
```

## Extending the Module

You can extend the functionality of the Content Creator module by creating custom LLM providers or adding support for additional field types.

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

### Common Issues

1. **Modal doesn't appear**: Make sure you have included the JavaScript and CSS in your project, and that the modal container is being rendered in the CMS.

2. **API errors**: Check your API keys and ensure they are correctly set in your AI Nexus configuration.

3. **Content not applying**: If content is not applying correctly, check that the field names match between the generated content and the page fields.

## API Reference

See [API Documentation](api.md) for detailed information on the module's classes and methods.
