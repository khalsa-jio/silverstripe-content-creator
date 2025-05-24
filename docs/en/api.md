# API Reference

## ContentGeneratorService

### Generator Methods

#### getPageFieldStructure(DataObject $page): array

Gets the field structure of a page, including Elemental blocks if applicable.

**Parameters:**

- `$page`: A DataObject instance (usually a SiteTree or subclass)

**Returns:** An array describing the fields and their types.

**Example:**

```php
$generator = Injector::inst()->get(ContentGeneratorService::class);
$structure = $generator->getPageFieldStructure($page);
```

#### generateContent(DataObject $page, string $prompt): array

Generates content for a page based on a prompt.

**Parameters:**

- `$page`: A DataObject instance (usually a SiteTree or subclass)
- `$prompt`: A string describing the content to generate

**Returns:** An array of generated content, structured to match the page fields.

**Example:**

```php
$generator = Injector::inst()->get(ContentGeneratorService::class);
$content = $generator->generateContent($page, "Create a product page about eco-friendly water bottles");
```

## LLMService

### LLMService Methods

#### setProvider(string $provider)

Sets the current LLM provider.

**Parameters:**

- `$provider`: The name of the provider (e.g., 'OpenAI', 'Claude')

**Returns:** `$this` for method chaining

**Example:**

```php
$service = LLMService::singleton();
$service->setProvider('Claude');
```

#### getProvider(): string

Gets the name of the current LLM provider.

**Returns:** The name of the current provider

**Example:**

```php
$service = LLMService::singleton();
$providerName = $service->getProvider();
```

#### generateContent(string $prompt, array $options = []): string

Generates content using the current LLM provider.

**Parameters:**

- `$prompt`: The prompt to send to the LLM
- `$options`: Optional array of provider-specific options

**Returns:** The generated content as a string

**Example:**

```php
$service = LLMService::singleton();
$content = $service->generateContent("Write a paragraph about climate change", [
    'temperature' => 0.5,
    'max_tokens' => 2000
]);
```

## ContentCreatorController

### Controller Methods

#### generate(HTTPRequest $request): HTTPResponse

API endpoint to generate content based on a prompt.

**POST Parameters:**

- `pageID`: The ID of the page to generate content for
- `prompt`: The prompt to use for content generation

**Returns:** JSON response with generated content or error information

#### getPageStructure(HTTPRequest $request): HTTPResponse

API endpoint to get the structure of a page.

**GET Parameters:**

- `pageID`: The ID of the page to get the structure for

**Returns:** JSON response with page structure information or error

#### applyContent(HTTPRequest $request): HTTPResponse

API endpoint to apply generated content to a page.

**POST Parameters:**

- `pageID`: The ID of the page to apply content to
- `content`: JSON or array of content to apply

**Returns:** JSON response with success or error information

## React Components

### ContentCreatorModal

A modal dialog for generating and previewing content.

**Props:**

- `show`: Boolean indicating whether the modal is visible
- `onHide`: Function to call when the modal is closed
- `pageID`: The ID of the page to generate content for

**Example:**

```jsx
<ContentCreatorModal
  show={true}
  onHide={() => setShow(false)}
  pageID={123}
/>
```
