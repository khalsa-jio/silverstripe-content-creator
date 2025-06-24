# SilverStripe Content Creator - Developer Documentation

This comprehensive guide covers all aspects of the SilverStripe Content Creator module for developers.

## Table of Contents

- [SilverStripe Content Creator - Developer Documentation](#silverstripe-content-creator---developer-documentation)
  - [Table of Contents](#table-of-contents)
  - [Architecture](#architecture)
  - [Configuration](#configuration)
    - [YAML Configuration](#yaml-configuration)
    - [ContentStructureService Configuration](#contentstructureservice-configuration)
      - [Field Exclusions](#field-exclusions)
      - [Relationship Inclusions](#relationship-inclusions)
      - [Specific Relations](#specific-relations)
      - [Cycle Detection and Recursion](#cycle-detection-and-recursion)
    - [ContentAIService Configuration](#contentaiservice-configuration)
      - [Field Format in Prompts](#field-format-in-prompts)
      - [Custom System Prompt](#custom-system-prompt)
      - [API Retry Configuration](#api-retry-configuration)
    - [ContentPopulatorService Configuration](#contentpopulatorservice-configuration)
  - [Caching](#caching)
    - [How Cache Keys Are Generated](#how-cache-keys-are-generated)
    - [Relationship Configuration](#relationship-configuration)
      - [Excluding Relationships](#excluding-relationships)
        - [Excluding by Class](#excluding-by-class)
        - [Excluding Specific Relations](#excluding-specific-relations)
      - [Customizing Related Object Fields](#customizing-related-object-fields)
      - [User-friendly Relationship Labels](#user-friendly-relationship-labels)
      - [Page Structure Display](#page-structure-display)
    - [Environment Variables](#environment-variables)
  - [Extending the Module](#extending-the-module)
    - [Adding Support for Additional Field Types](#adding-support-for-additional-field-types)
    - [Customizing Relationships](#customizing-relationships)
  - [Developer Tools](#developer-tools)
    - [PromptPreviewTask](#promptpreviewtask)
    - [Debugging Generated Prompts](#debugging-generated-prompts)
    - [Understanding Field Formats](#understanding-field-formats)
  - [Testing](#testing)
  - [Common Issues](#common-issues)

## Architecture

The Content Creator module consists of several key components:

1. **Specialized Core Services**:
   - **ContentStructureService**: Analyzes DataObject structure, fields, relationships, and ElementalAreas
   - **ContentAIService**: Handles communication with AI models and builds prompts based on the page structure
   - **ContentPopulatorService**: Populates DataObjects with generated content
   - **ContentCacheService**: Provides caching mechanisms for improved performance

2. **ContentCreatorExtension**: An Extension that can be applied to DataObject and will add the content generation UI to the CMS.

3. **ContentCreatorController**: A controller that handles requests from the UI.

4. **React Components**: React components for the content generation UI, including the modal dialog and content preview.

5. **LLMClient**: This is used to interact with various LLM providers (OpenAI, Anthropic, etc.) to generate content based on user prompts.

## Configuration

### YAML Configuration

The module can be configured in your project's YAML config files. Here's an example configuration:

```yaml
---
Name: my-content-creator-config
After: '#content-creator-config'
---
KhalsaJio\ContentCreator\Extensions\ContentCreatorExtension:
  enabled_page_types:
    - SilverStripe\CMS\Model\SiteTree
  excluded_page_types:
    - SilverStripe\ErrorPage\ErrorPage
```

### ContentStructureService Configuration

The ContentStructureService provides several configuration options to control how page structures are analyzed and presented:

#### Field Exclusions

Control which fields should be excluded from the content structure:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  excluded_field_names:
    - 'ID'
    - 'Created' 
    - 'LastEdited'
    # Add your custom fields to exclude here
```

#### Relationship Inclusions

Specify which relationship classes should be included in the content structure:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  included_relationship_classes:
    - 'My\App\RelationshipClass'
```

#### Specific Relations

Include specific relations by specifying the class and relation name:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  included_specific_relations:
    - 'My\App\Class.RelationName'
    - 'Another\App\Class.AnotherRelation'
```

> **Important:** When `included_specific_relations` is configured, the ContentStructureService switches to an "allowlist" mode where it will **only** include the explicitly specified relations in the content structure and ignore all other relationships. This overrides any classes specified in `included_relationship_classes`. Use this configuration when you need precise control over exactly which relations should be included.

#### Cycle Detection and Recursion

The ContentStructureService includes robust cycle detection for nested ElementalAreas and relationship fields:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  max_recursion_depth: 5 # Default value, adjust as needed
```

### ContentAIService Configuration

The ContentAIService is responsible for interaction with AI models for content generation.

#### Field Format in Prompts

The AI service uses a specific format for fields in prompts to assist the LLM in understanding the structure:

- Fields are formatted as: `Field Title (FieldName): FieldType - Description`
- Options are formatted as: `Options: [value1: label1, value2: label2, ...]`

This format helps the AI model understand the structure and generate appropriate content.

#### Custom System Prompt

You can customize the system prompt used for AI content generation:

```yaml
KhalsaJio\ContentCreator\Services\ContentAIService:
  custom_system_prompt: "Your custom system prompt here. {structureDescription} will be replaced with the actual structure."
```

#### API Retry Configuration

The ContentAIService implements retry logic with exponential backoff for handling transient API failures. You can configure the retry behavior:

```yaml
KhalsaJio\ContentCreator\Services\ContentAIService:
  max_retries: 3                      # Maximum number of retry attempts
  initial_backoff_ms: 1000            # Initial wait time in milliseconds
  backoff_multiplier: 2.0             # Multiplier for subsequent backoff times
  retryable_errors:                   # Error types that should trigger a retry
    - rate_limit
    - timeout
    - connection
    - server_error
    - unknown
```

This helps handle temporary network issues, rate limiting, and other transient errors when communicating with LLM providers.

### ContentPopulatorService Configuration

The ContentPopulatorService handles the population of generated content into SilverStripe objects.

#### Field Name Mapping

The ContentPopulatorService relies on the exact field names (those shown in brackets in the LLM prompt) to map generated content to the appropriate fields in SilverStripe objects. This is why the system prompt explicitly instructs the LLM to use these exact field names in the generated YAML.

For elemental blocks, the service uses the `BlockType`, `ClassName`, `Class`, or `Type` field to determine the correct class for instantiating block objects. The system prompt instructs the LLM to include the full class name as shown in brackets in the prompt.

## Caching

The ContentCacheService provides caching mechanisms to improve performance by reducing redundant calculations and API requests. This is particularly useful for complex pages with many fields or nested structures where analyzing the structure repeatedly would impact performance.

### How Cache Keys Are Generated

Cache keys are intelligently generated using:

1. The DataObject's class name
2. The DataObject's ID
3. The DataObject's last edited timestamp

This ensures that:

- Each unique object gets its own cache entry
- The cache is automatically invalidated when the object is updated
- Different versions of the same object don't use outdated cache data

### Relationship Configuration

The Content Creator module offers several ways to customize how relationship fields (has_one, has_many, many_many)
are handled during content generation.

#### Excluding Relationships

Some relationships shouldn't be included in content generation, such as internal framework classes or complex relationships
that are better managed manually. You can exclude relationships in two ways:

##### Excluding by Class

To exclude all relationships to specific classes:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  excluded_relationship_classes:
    - SilverStripe\SiteConfig\SiteConfig
    - SilverStripe\CMS\Model\SiteTree
    - DNADesign\Elemental\Models\ElementalArea
    - DNADesign\Elemental\Models\BaseElement
    - YourNamespace\YourExcludedClass
```

This will exclude any relation where the target class is or extends one of these classes.

##### Excluding Specific Relations

To exclude specific relations while keeping others of the same class:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  excluded_specific_relations:
    - 'App\Blocks\Cards\CardRowBlock.Cards'
    - 'App\Blocks\Accordion\AccordionBlock.Accordions'
```

The format is `OwnerClass.RelationName`.

#### Customizing Related Object Fields

By default, the Content Creator will request all fields for related objects. You can specify which fields
to include for each related class:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  related_object_fields:
    'App\Models\Product':
      - Title
      - Price
      - Description
    'App\Models\Author':
      - Name
      - Biography
```

#### User-friendly Relationship Labels

You can customize how relationships are described in the content generation prompts:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  relationship_labels:
    has_one: 'Single related item'
    has_many: 'Multiple related items'
    many_many: 'Multiple related items'
    belongs_many_many: 'Multiple related items'
```

#### Page Structure Display

You can control whether the page structure is displayed in the content creator modal:

```yaml
KhalsaJio\ContentCreator\Services\ContentStructureService:
  show_page_structure: false # Set to false to hide the page structure in the modal
```

By default, the page structure is shown in the modal (`show_page_structure: true`). Setting this to `false` will hide the page structure section, allowing for a cleaner interface when users don't need to see the underlying structure of the page.

### Environment Variables

For security reasons, it's recommended to store API keys as environment variables.
You can reference environment variables in SilverStripe YAML configuration using the appropriate Injector pattern, to setup your LLM providers, follow [AI Nexus integration](https://github.com/khalsa-jio/silverstripe-ai-nexus)

## Extending the Module

### Adding Support for Additional Field Types

By default, the ContentStructureService recognizes standard field types like TextField, TextareaField, and HTMLEditorField as content fields. To add support for additional field types, you can create a custom extension for the ContentStructureService.

```php
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FormField;
use Your\Custom\Field;

class ContentStructureServiceExtension extends Extension
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
KhalsaJio\ContentCreator\Services\ContentStructureService:
  extensions:
    - Your\Extension\ContentStructureServiceExtension
```

### Customizing Relationships

You can also extend relationship configurations programmatically:

## Developer Tools

### PromptPreviewTask

The module includes a development task for previewing how the LLM prompt would be constructed for any Page or Block class. This is useful for debugging and optimizing prompt structures:

### Debugging Generated Prompts

When troubleshooting content generation issues, it can be useful to see the actual prompts being sent to the LLM:

```php
use KhalsaJio\ContentCreator\Services\ContentAIService;
use SilverStripe\Core\Injector\Injector;

$aiService = Injector::inst()->get(ContentAIService::class);
$page = Page::get()->byID(123);

// Get the system prompt that would be used for this page
$systemPrompt = $aiService->buildSystemPrompt($page);
echo $systemPrompt;
```

### Understanding Field Formats

The ContentAIService uses a specific format for fields in prompts:

```text
Field Title (FieldName): FieldType - Field Description
```

For example:

```text
Page Title (Title): Text - The main title of the page
Main Content (Content): HTMLText - The main content area that supports HTML formatting
```

This format helps the LLM understand the structure of the page and generate appropriate content.

**Important**: The field name in brackets (e.g., `Title`, `Content`) is the actual field name that must be used in the generated YAML output. The system prompt explicitly instructs the LLM to use these exact field names when generating content. This ensures that the generated content can be correctly mapped back to the SilverStripe fields without requiring additional transformation.

For elemental blocks, the system prompt also instructs the LLM to include the `BlockType` or `ClassName` field with the full class name shown in brackets, which is essential for correct instantiation of the appropriate block classes.

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

## Common Issues

1. **Modal doesn't appear**: Make sure you have included the JavaScript and CSS in your project, and that the modal container is being rendered in the CMS.

2. **API errors**: Check your API keys and ensure they are correctly set in your AI Nexus configuration.

3. **Content not applying**: If content is not applying correctly, check that the field names match between the generated content and the page fields.

4. **Issues with nested ElementalAreas**: If you're having issues with nested blocks not generating correctly, check for potential cycles in your ElementalArea structure or increase the max_recursion_depth in your configuration.

5. **Field type recognition**: If certain field types aren't being recognized properly, you may need to add them to the ContentStructureService's content field types using an extension.

6. **LLM prompt formatting**: If the generated content doesn't match your expected structure, use the PromptPreviewTask to inspect the prompt being sent to the LLM and adjust field descriptions or titles as needed.
