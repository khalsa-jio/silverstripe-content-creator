<?php

namespace KhalsaJio\ContentCreator\Controllers;

use KhalsaJio\ContentCreator\Models\ContentCreationEvent;
use KhalsaJio\ContentCreator\Services\LLMService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Versioned\Versioned;

/**
 * Controller for handling content generation requests
 */
class ContentCreatorController extends Controller
{
    private static $url_segment = 'contentcreator';

    private static $url_handlers = [
        'POST generate' => 'generate',
        'GET getPageStructure' => 'getPageStructure',
        'POST applyContent' => 'applyContent',
        'GET debug' => 'debug',
    ];

    /**
     * Allowed actions for this controller
     */
    private static $allowed_actions = [
        'generate',
        'getPageStructure',
        'applyContent',
        'debug',
    ];

    /**
     * Generate content based on a prompt
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function generate(HTTPRequest $request)
    {
        if (!$this->validateRequest($request)) {
            return $this->jsonResponse(['error' => 'Invalid request'], 400);
        }

        $jsonBody = $this->getJsonBody($request);
        
        if ($jsonBody) {
            $dataObjectID = isset($jsonBody['dataObjectID']) ? $jsonBody['dataObjectID'] : null;
            $dataObjectClass = isset($jsonBody['dataObjectClass']) ? $jsonBody['dataObjectClass'] : null;
            $prompt = isset($jsonBody['prompt']) ? $jsonBody['prompt'] : null;
            $streaming = isset($jsonBody['streaming']) ? (bool)$jsonBody['streaming'] : false;
        }

        if (!$dataObjectID || !$dataObjectClass || !$prompt) {
            return $this->jsonResponse(['error' => 'Missing required parameters: dataObjectID, dataObjectClass, prompt'], 400);
        }

        $dataObject = $this->loadDataObject($dataObjectClass, $dataObjectID);

        if (!$dataObject instanceof DataObject && isset($dataObject['error'])) {
            return $this->jsonResponse($dataObject, $dataObject['code']);
        }

        // If streaming is requested, handle it with SSE
        if ($streaming) {
            return $this->generateStreamingResponse($dataObject, $prompt);
        }

        // Otherwise, generate content normally
        $generator = Injector::inst()->get(ContentGeneratorService::class);

        try {
            $content = $generator->generateContent($dataObject, $prompt);

            return $this->jsonResponse([
                'success' => true,
                'content' => $content
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the structure of a page for display in the UI
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function getPageStructure(HTTPRequest $request)
    {
        if (!$this->validateRequest($request)) {
            return $this->jsonResponse(['error' => 'Invalid request'], 400);
        }

        $className = $request->getVar('dataObjectClass');
        $id = $request->getVar('dataObjectID');

        if (!$className || !$id) {
            return $this->jsonResponse(['error' => 'Missing required parameters: dataObjectClass and dataObjectID'], 400);
        }

        $dataObject = $this->loadDataObject($className, $id, true);

        if (!$dataObject instanceof DataObject &&  isset($dataObject['error'])) {
            return $this->jsonResponse($dataObject, $dataObject['code']);
        }

        try {
            $generator = Injector::inst()->get(ContentGeneratorService::class);
            $structure = $generator->getPageFieldStructure($dataObject);

            return $this->jsonResponse([
                'success' => true,
                'structure' => $structure
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => 'An unexpected error occurred while fetching the structure: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply the generated content to the page
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function applyContent(HTTPRequest $request)
    {
        if (!$this->validateRequest($request)) {
            return $this->jsonResponse(['error' => 'Invalid request'], 400);
        }

        // Try to get data from JSON body first
        $jsonBody = $this->getJsonBody($request);
        
        if ($jsonBody) {
            $dataObjectID = isset($jsonBody['dataObjectID']) ? $jsonBody['dataObjectID'] : null;
            $dataObjectClass = isset($jsonBody['dataObjectClass']) ? $jsonBody['dataObjectClass'] : null;
            $contentData = isset($jsonBody['content']) ? $jsonBody['content'] : null;
        }

        if (!$dataObjectID || !$dataObjectClass || !$contentData) {
            return $this->jsonResponse(['error' => 'Missing required parameters: dataObjectID, dataObjectClass, content'], 400);
        }

        $dataObject = $this->loadDataObject($dataObjectClass, $dataObjectID);

        if (!$dataObject instanceof DataObject && isset($dataObject['error'])) {
            return $this->jsonResponse($dataObject, $dataObject['code']);
        }

        $contentData = $this->parseContentData($contentData);
        if ($contentData === false) {
            return $this->jsonResponse(['error' => 'Invalid content data format'], 400);
        }

        try {
            $this->populatePageFields($dataObject, $contentData);

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Content successfully applied to the ' . $dataObject->singular_name()
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually populate page fields if the Populate module is not available
     *
     * @param DataObject $dataObject
     * @param array $data
     * TODO: Need to handle complex field types (e.g. ManyMany, HasMany, etc.)
     */
    protected function populatePageFields(DataObject $dataObject, array $data)
    {
        foreach ($data as $fieldName => $value) {
            // Skip fields that don't exist on the page
            if (!$dataObject->hasField($fieldName) && !$dataObject->hasMethod("set$fieldName")) {
                continue;
            }

            // Set the field value
            $dataObject->$fieldName = $value;
        }

        $dataObject->write();
    }

    /**
     * Validate that the request is valid
     *
     * @param HTTPRequest $request
     * @return bool
     */
    protected function validateRequest(HTTPRequest $request)
    {
        // Check permissions
        if (!$request->isAjax() || !Permission::check('CMS_ACCESS_CMSMain')) {
            return false;
        }
        return true;
    }

    /**
     * Helper to create a JSON response
     *
     * @param array $data
     * @param int $statusCode
     * @return HTTPResponse
     */
    protected function jsonResponse(array $data, int $statusCode = 200): HTTPResponse
    {
        $response = new HTTPResponse(json_encode($data), $statusCode);
        $response->addHeader('Content-Type', 'application/json');
        return $response;
    }

    /**
     * Simple debug route to verify controller loading
     */
    public function debug()
    {
        return json_encode([
            'success' => true,
            'message' => 'ContentCreatorController loaded successfully',
            'namespace' => __NAMESPACE__,
            'class' => __CLASS__,
            'file' => __FILE__
        ]);
    }

    /**
     * Helper to load a DataObject with proper handling of Versioned records
     *
     * @param string $className The DataObject class name to load
     * @param int $id The DataObject ID to load
     * @param bool $checkLiveFirst Whether to check the Live stage first (true) or Draft first (false)
     * @return array|DataObject Returns the DataObject if found, otherwise an error response array
     */
    protected function loadDataObject(string $className, int $id, bool $checkLiveFirst = false)
    {
        // Validate class exists and is a DataObject
        if (!class_exists($className) || !is_subclass_of($className, DataObject::class)) {
            return ['error' => 'Invalid dataObjectClass: ' . $className, 'code' => 400];
        }

        $dataObject = null;
        
        // Handle versioned DataObjects
        if (DataObject::has_extension($className, Versioned::class)) {
            // Determine which stage to check first
            $firstStage = $checkLiveFirst ? Versioned::LIVE : Versioned::DRAFT;
            $secondStage = $checkLiveFirst ? Versioned::DRAFT : Versioned::LIVE;
            
            // Try to load from the first stage
            $dataObject = Versioned::get_by_stage($className, $firstStage)->byID($id);
            
            // If not found, try the second stage
            if (!$dataObject || !$dataObject->exists()) {
                $dataObject = Versioned::get_by_stage($className, $secondStage)->byID($id);
            }
        } else {
            // Regular DataObject (not versioned)
            $dataObject = DataObject::get($className)->byID($id);
        }

        // Return error if not found
        if (!$dataObject || !$dataObject->exists()) {
            $objectName = singleton($className)->i18n_singular_name();
            return ['error' => $objectName . ' not found', 'code' => 404];
        }

        return $dataObject;
    }

    /**
     * Parse JSON content data from a string or array
     *
     * @param string|array $contentData The content data to parse
     * @return array|false Returns the parsed data as array, or false on error
     */
    protected function parseContentData($contentData)
    {
        if (is_array($contentData)) {
            return $contentData;
        }
        
        if (is_string($contentData)) {
            $parsed = json_decode($contentData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
            return $parsed;
        }
        
        return false;
    }

    /**
     * Parse JSON from the request body
     *
     * @param HTTPRequest $request
     * @return array|null The parsed JSON data or null if not valid JSON
     */
    protected function getJsonBody(HTTPRequest $request)
    {
        $body = $request->getBody();
        
        if (!$body) {
            return null;
        }
        
        $json = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $json;
    }

    /**
     * Generate content with streaming response (Server-Sent Events)
     * 
     * @param DataObject $dataObject The data object to generate content for
     * @param string $prompt The prompt to use for content generation
     * @return HTTPResponse
     */
    protected function generateStreamingResponse(DataObject $dataObject, string $prompt)
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');  // Disable buffering for Nginx

        // Send the initial response headers to the client
        http_response_code(200);

        try {
            // Start buffering to collect content for analytics
            $fullContent = [];

            // Get LLM service directly to use streaming capability
            $llmService = Injector::inst()->get(LLMService::class);

            // Get structure for field mapping
            $generator = Injector::inst()->get(ContentGeneratorService::class);
            $pageStructure = $generator->getPageFieldStructure($dataObject);

            // Begin the response
            $this->sendSSEEvent('start', ['status' => 'started']);

            // Prepare the context-aware system prompt
            $systemPrompt = $generator->buildSystemPrompt($dataObject, $pageStructure);
            $fullPrompt = $systemPrompt . "\n\n" . $prompt;

            // Initialize the fields array that will store the content
            $fields = [];
            foreach ($pageStructure as $field) {
                $fields[$field['name']] = '';
            }

            // Callback function to handle streaming chunks
            $streamCallback = function($chunk) use (&$fullContent) {
                $fullContent[] = $chunk;

                // Send the chunk as an SSE event
                $this->sendSSEEvent('chunk', ['text' => $chunk]);
            };

            // Generate content using streaming
            $llmService->generateContentStreaming($fullPrompt, [], $streamCallback);

            // Process the complete content once streaming is complete
            $completeContent = implode('', $fullContent);
            $parsedContent = $generator->parseGeneratedContent($completeContent, $pageStructure);

            // Send the complete processed content
            $this->sendSSEEvent('complete', ['content' => $parsedContent]);

            // Record the analytics
            $event = ContentCreationEvent::create([
                'DataObjectID' => $dataObject->ID,
                'DataObjectClass' => get_class($dataObject),
                'Prompt' => $prompt,
                'Success' => true
            ]);
            $event->write();

            // End the response
            $this->sendSSEEvent('end', ['status' => 'completed']);

            // Make sure everything is sent
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            // The custom response doesn't need to be returned
            exit();
        } catch (\Exception $e) {
            // In case of an error, send an error event
            $this->sendSSEEvent('error', ['error' => $e->getMessage()]);

            // Make sure everything is sent
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            exit();
        }
    }

    /**
     * Send a Server-Sent Event
     * 
     * @param string $event The event name
     * @param array $data The data to send with the event
     * @return void
     */
    protected function sendSSEEvent(string $event, array $data = []): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        
        // Flush the output buffer to send data immediately
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
