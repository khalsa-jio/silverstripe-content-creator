<?php

namespace KhalsaJio\ContentCreator\Tasks;

use Exception;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Director;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use KhalsaJio\ContentCreator\Services\ContentAIService;
use KhalsaJio\ContentCreator\Services\ContentStructureService;

/**
 * A BuildTask that allows previewing the prompt that will be sent to LLM providers
 * for a given Page or Block class
 *
 * Usage:
 * - /dev/tasks/prompt-preview - Shows a list of available Page and Block classes
 * - /dev/tasks/prompt-preview?class=App\Blocks\ContentBlock - Shows a prompt for the specified class
 */
class PromptPreviewTask extends BuildTask
{
    /**
     * @var string
     */
    private static $segment = 'prompt-preview';

    /**
     * @var string
     */
    protected $title = 'Preview Content Generation LLM Prompts';

    /**
     * @var string
     */
    protected $description = 'Preview the system prompt and structure that would be sent to LLM providers for Page and Block content generation';

    /**
     * Run the task
     *
     * @param HTTPRequest $request
     * @return void
     */
    public function run($request)
    {
        // Get class name from request
        $className = $request->getVar('class');

        // Get format option - text or html (default html)
        $format = $request->getVar('format');
        $isText = $format === 'text';

        // Get option to show just structure instead of full prompt
        $structureOnly = $request->getVar('structure') === '1';

        // Set content type based on format
        if (!$isText) {
            header('Content-Type: text/html; charset=utf-8');
        } else {
            header('Content-Type: text/plain; charset=utf-8');
        }

        try {
            // If no class specified, show available options
            if (!$className) {
                return $this->showClassOptions($isText);
            }

            try {
                // First check if class exists
                if (!class_exists($className)) {
                    throw new Exception("Class '{$className}' does not exist.");
                }

                // Get an object instance to analyze
                $object = $this->getObjectInstance($className);

                if (!$object) {
                    throw new Exception("Could not create an instance of '{$className}'.");
                }

                // Verify it's a DataObject
                if (!($object instanceof DataObject)) {
                    throw new Exception("'{$className}' is not a DataObject class.");
                }
            } catch (Exception $instanceEx) {
                if ($isText) {
                    echo "Error: {$instanceEx->getMessage()}\n\n";
                    echo "Please select a valid class from the list below:\n\n";
                    $this->showClassOptions($isText);
                } else {
                    echo "<h1>Error</h1>";
                    echo "<p>" . htmlspecialchars($instanceEx->getMessage()) . "</p>";
                    echo "<hr>";
                    echo "<p>Please select a valid class from the list below:</p>";
                    $this->showClassOptions($isText);
                }
                return;
            }

            // Get the ContentAIService
            $aiService = Injector::inst()->get(ContentAIService::class);

            if ($structureOnly) {
                // Get just the structure part of the prompt
                $structure = Injector::inst()->get(ContentStructureService::class)
                    ->getPageFieldStructure($object);
                $structureDescription = $aiService->formatStructureForPrompt($structure);

                if ($isText) {
                    echo "# Structure Preview for {$className}\n\n";
                    echo $structureDescription;
                } else {
                    echo "<h1>Structure Preview for {$className}</h1>";
                    echo "<pre>" . htmlspecialchars($structureDescription) . "</pre>";
                }
            } else {
                // Get the full system prompt
                $systemPrompt = $aiService->buildSystemPrompt($object);

                // Create a demo user prompt
                $userPrompt = $this->getDemoUserPrompt($object);

                // Display the prompts
                if ($isText) {
                    echo "# Full Prompt Preview for {$className}\n\n";
                    echo "## System Prompt:\n\n";
                    echo $systemPrompt . "\n\n";
                    echo "## User Prompt (demo):\n\n";
                    echo $userPrompt . "\n\n";
                    echo "## Total Tokens (estimate):\n\n";
                    echo "Approximate token count: " . $this->estimateTokens($systemPrompt . " " . $userPrompt) . "\n";
                } else {
                    echo "<h1>Full Prompt Preview for {$className}</h1>";

                    echo "<h2>System Prompt:</h2>";
                    echo "<pre>" . htmlspecialchars($systemPrompt) . "</pre>";

                    echo "<h2>User Prompt (demo):</h2>";
                    echo "<pre>" . htmlspecialchars($userPrompt) . "</pre>";

                    echo "<h2>Total Tokens (estimate):</h2>";
                    echo "<p>Approximate token count: " . $this->estimateTokens($systemPrompt . " " . $userPrompt) . "</p>";
                }
            }
        } catch (Exception $e) {
            if ($isText) {
                echo "Error: {$e->getMessage()}\n\n";
                echo "Details: " . $e->getTraceAsString() . "\n\n";
                echo "Please try another class or check the error log for more details.\n\n";
                echo "Available classes:\n\n";
                $this->showClassOptions($isText);
            } else {
                echo "<h1>Error</h1>";
                echo "<p><strong>" . htmlspecialchars($e->getMessage()) . "</strong></p>";
                echo "<p>Please try another class or check the error log for more details.</p>";

                // Show error details in a collapsible section
                echo "<details>";
                echo "<summary>View error details</summary>";
                echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
                echo "</details>";

                echo "<hr>";
                echo "<h2>Available Classes</h2>";
                echo "<p>Please select a class from the list below:</p>";
                $this->showClassOptions($isText);
            }
        }
    }

    /**
     * Show a list of available Page classes and Block classes
     *
     * @param bool $isText Whether to output as text or HTML
     * @param bool $fullPage Whether to show full page headers (false when called from error handler)
     * @return void
     */
    protected function showClassOptions(bool $isText, bool $fullPage = true): void
    {
        // Get subclasses of Page
        $pageClasses = ClassInfo::subclassesFor('Page');

        // Get subclasses that contain 'Block' in the name or namespace
        $dataObjectClasses = ClassInfo::subclassesFor(DataObject::class);
        $blockClasses = array_filter($dataObjectClasses, function($className) {
            return strpos($className, '\\Blocks\\') !== false ||
                    strpos($className, 'Block') !== false ||
                    strpos($className, 'Element') !== false;
        });

        // Combine and remove duplicates
        $allClasses = array_unique(array_merge($pageClasses, $blockClasses));

        // Remove base Page class if it exists
        if (($key = array_search('Page', $allClasses)) !== false) {
            unset($allClasses[$key]);
        }

        // Sort classes alphabetically
        sort($allClasses);

        if ($isText) {
            if ($fullPage) {
                echo "# Content Generation Prompt Preview Tool\n\n";
                echo "This tool allows you to preview the LLM prompt for Pages and Block classes.\n\n";
                echo "## Usage\n\n";
                echo "- /dev/tasks/prompt-preview?class=[ClassName] - View prompt for a specific class\n";
                echo "- /dev/tasks/prompt-preview?class=[ClassName]&format=text - View in plain text format\n";
                echo "- /dev/tasks/prompt-preview?class=[ClassName]&structure=1 - View just structure part\n\n";
            }

            echo "## Available Page & Block Classes\n\n";
            foreach ($allClasses as $class) {
                echo "- " . $class . "\n";
                echo "  " . Director::absoluteURL("/dev/tasks/prompt-preview?class=" . urlencode($class)) . "\n\n";
            }
        } else {
            if ($fullPage) {
                echo "<h1>Content Generation Prompt Preview Tool</h1>";
                echo "<p>This tool allows you to preview the LLM prompt for Pages and Block classes.</p>";

                echo "<h2>Usage</h2>";
                echo "<ul>";
                echo "<li><code>/dev/tasks/prompt-preview?class=[ClassName]</code> - View prompt for a specific class</li>";
                echo "<li><code>/dev/tasks/prompt-preview?class=[ClassName]&format=text</code> - View in plain text format</li>";
                echo "<li><code>/dev/tasks/prompt-preview?class=[ClassName]&structure=1</code> - View just structure part</li>";
                echo "</ul>";
            }

            echo "<h2>Available Page & Block Classes</h2>";

            echo "<ul style=\"max-height: 400px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;\">";
            foreach ($allClasses as $class) {
                $url = Director::absoluteURL("/dev/tasks/prompt-preview?class=" . urlencode($class));
                echo "<li><a href=\"{$url}\">{$class}</a></li>";
            }
            echo "</ul>";
        }        }

    /**
     * Get an instance of the specified class, preferring published pages and blocks with content
     * Falls back to singleton if needed
     *
     * @param string $className
     * @return DataObject|null
     */
    protected function getObjectInstance(string $className): ?DataObject
    {
        if (!class_exists($className)) {
            return null;
        }

        // Always create a singleton first as a fallback
        $object = singleton($className);
        if (!$object) {
            return null;
        }

        // Try to get a real instance with data, but don't fail if we can't
        try {
            if (is_a($className, SiteTree::class, true)) {
                // For pages, try to get a published page first
                $realObject = $className::get()->filter(['WasPublished' => 1])->first();

                // If no published page, try any page
                if (!$realObject) {
                    $realObject = $className::get()->first();
                }

                // Use the real object if we found one
                if ($realObject) {
                    $object = $realObject;
                }
            } else if (
                is_a($className, 'DNADesign\Elemental\Models\BaseElement', true) ||
                    strpos($className, '\\Blocks\\') !== false ||
                    strpos($className, 'Block') !== false
            ) {
                // For blocks or elements, try to get one with content
                $realObject = $className::get()->sort('ID', 'DESC')->first();

                if ($realObject) {
                    $object = $realObject;
                }
            }
        } catch (Exception $e) {
            //
        }

        return $object;
    }

    /**
     * Get a demo user prompt appropriate for the object type
     *
     * @param DataObject $object
     * @return string
     */
    protected function getDemoUserPrompt(DataObject $object): string
    {
        // Get the class name without namespace
        $className = get_class($object);
        $parts = explode('\\', $className);
        $shortClassName = end($parts);

        // Create a prompt based on the class type
        if (strpos($className, '\\Blocks\\') !== false || strpos($shortClassName, 'Block') !== false) {
            return "Generate content for this {$shortClassName} block that provides engaging and informative content. The block should explain a concept clearly and include appropriate visuals or formatting.";
        } elseif (strpos($className, '\\Page') !== false || $shortClassName === 'Page') {
            return "Generate content for this {$shortClassName} about sustainable living practices. Include sections on energy conservation, waste reduction, and eco-friendly product alternatives. The content should be engaging, informative, and suitable for a general audience.";
        } else {
            return "Generate appropriate content for this {$shortClassName} object. Make sure the content is realistic, informative, and fits the object's purpose.";
        }
    }

    /**
     * Get a very rough estimate of tokens for a string
     * Note: This is a very simple approximation, not accurate for all languages
     *
     * @param string $text
     * @return int
     */
    protected function estimateTokens(string $text): int
    {
        // Rough estimate: about 4 characters per token for English text
        return (int) ceil(mb_strlen($text) / 4);
    }
}
