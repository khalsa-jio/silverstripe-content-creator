<?php

namespace KhalsaJio\ContentCreator\Controllers;

use KhalsaJio\ContentCreator\Models\ContentCreationEvent;
use KhalsaJio\ContentCreator\Services\ContentAIService;
use KhalsaJio\ContentCreator\Services\ContentPopulatorService;
use KhalsaJio\ContentCreator\Services\ContentStructureService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Versioned\Versioned;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling content generation requests
 */
class ContentCreatorController extends Controller
{
    private static $url_segment = 'contentcreator';

    private static $url_handlers = [
        'generate' => 'generate',
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
     * Generate content based on a prompt, supporting both streaming and non-streaming.
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function generate(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isGET() && !$request->isPOST()) {
            return $this->jsonResponse(['error' => 'Invalid request', 'success' => false], 400);
        }

        // Get parameters from body or query params depending on request method
        if ($request->isGET()) {
            // For GET requests (EventSource), get params from URL
            $dataObjectID = $request->getVar('dataObjectID');
            $dataObjectClass = $request->getVar('dataObjectClass');
            $prompt = $request->getVar('prompt');
            $streaming = $request->getVar('streaming') === 'true';
            $maxTokens = (int)($request->getVar('max_tokens') ?? 4000);
            $temperature = (float)($request->getVar('temperature') ?? 0.7);
        } else {
            // For POST requests, get params from JSON body
            $params = $this->getJsonBody($request);
            $dataObjectID = $params['dataObjectID'] ?? null;
            $dataObjectClass = $params['dataObjectClass'] ?? null;
            $prompt = $params['prompt'] ?? null;
            $streaming = isset($params['streaming']) && ($params['streaming'] === 'true' || $params['streaming'] === '1' || $params['streaming'] === true);
            $maxTokens = isset($params['max_tokens']) ? (int)$params['max_tokens'] : 4000;
            $temperature = isset($params['temperature']) ? (float)$params['temperature'] : 0.7;
        }

        // Handle URL-encoded prompt parameter which is sent by EventSource requests
        if ($prompt && strpos($prompt, '%') !== false) {
            $prompt = urldecode($prompt);
        }

        if (!$dataObjectID || !$dataObjectClass || !$prompt) {
            return $this->jsonResponse(['error' => 'Missing required parameters: dataObjectID, dataObjectClass, prompt', 'success' => false], 400);
        }

        $dataObject = $this->loadDataObject($dataObjectClass, $dataObjectID);
        if (!$dataObject instanceof DataObject && isset($dataObject['error'])) {
            return $this->jsonResponse(['error' => $dataObject['error'], 'success' => false, 'code' => $dataObject['code'] ?? 400], $dataObject['code'] ?? 400);
        }

        /** @var ContentAIService $contentGenerator */
        $contentGenerator = Injector::inst()->get(ContentAIService::class);

        if ($streaming) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Ensure headers are not sent before this point
            if (headers_sent($file, $line)) {
                $this->recordContentGenerationEvent($dataObjectID, $dataObjectClass, $prompt, false, [], "Headers already sent at {$file}:{$line}", true);
                echo "event: error\ndata: " . json_encode(['error' => "Cannot start stream: Headers already sent."]) . "\n\n";
                echo "event: end\ndata: " . json_encode(['status' => 'failed']) . "\n\n";
                flush();
                exit();
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            http_response_code(200);

            try {
                $contentGenerator->generateStreamContent(
                    $dataObject,
                    $prompt,
                    $maxTokens,
                    $temperature,
                    // Chunk callback
                    function ($text) {
                        echo "event: chunk\ndata: " . json_encode(['text' => $text]) . "\n\n";
                        flush();
                    },
                    // Complete callback
                    function ($parsedContent, $usage) use ($dataObjectID, $dataObjectClass, $prompt) {
                        echo "event: complete\ndata: " . json_encode(['content' => $parsedContent, 'usage' => $usage]) . "\n\n";
                        $this->recordContentGenerationEvent($dataObjectID, $dataObjectClass, $prompt, true, $usage, null, true);
                        echo "event: end\ndata: " . json_encode(['status' => 'completed']) . "\n\n";
                        flush();
                    },
                    // Error callback
                    function ($exception) use ($dataObjectID, $dataObjectClass, $prompt) {
                        echo "event: error\ndata: " . json_encode(['error' => $exception->getMessage()]) . "\n\n";
                        $this->recordContentGenerationEvent($dataObjectID, $dataObjectClass, $prompt, false, [], $exception->getMessage(), true);
                        echo "event: end\ndata: " . json_encode(['status' => 'failed']) . "\n\n";
                        flush();
                    }
                );
            } catch (\Exception $e) {
                echo "event: error\ndata: " . json_encode(['error' => "Stream initiation failed: " . $e->getMessage()]) . "\n\n";
                $this->recordContentGenerationEvent($dataObjectID, $dataObjectClass, $prompt, false, [], "Stream initiation failed: " . $e->getMessage(), true);
                echo "event: end\ndata: " . json_encode(['status' => 'failed']) . "\n\n";
                flush();
            }
            exit();
        } else { // Non-streaming
            try {
                $generatedContent = $contentGenerator->generateContent($dataObject, $prompt);

                return $this->jsonResponse([
                    'success' => true,
                    'content' => $generatedContent
                ]);
            } catch (\Exception $e) {
                $this->recordContentGenerationEvent($dataObjectID, $dataObjectClass, $prompt, false, [], $e->getMessage());
                return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
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
            $structureService = Injector::inst()->get(ContentStructureService::class);
            $structure = $structureService->getPageFieldStructure($dataObject);
            $showPageStructure = $structureService->shouldShowPageStructure();

            return $this->jsonResponse([
                'success' => true,
                'structure' => $structure,
                'showPageStructure' => $showPageStructure
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
            $populatorService = Injector::inst()->get(ContentPopulatorService::class);
            $populatorService->populateContent($dataObject, $contentData);

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
     * Helper method to record content generation events
     */
    protected function recordContentGenerationEvent(
        $dataObjectID,
        $dataObjectClass,
        $prompt,
        $success = true,
        $usage = [],
        $errorMessage = null,
        $isStreaming = false
    ): void {
        try {
            /** @var ContentCreationEvent $event */
            $event = ContentCreationEvent::create();
            $event->Type = $success ? 'generation_completed' : 'generation_failed';
            $event->RelatedObjectID = (int)$dataObjectID;
            $event->RelatedObjectClass = (string)$dataObjectClass;
            $event->Success = $success;

            // Set token usage if available
            if (!empty($usage) && isset($usage['total_tokens'])) {
                $event->TokensUsed = (int)$usage['total_tokens'];
            }

            $eventData = [
                'prompt_length' => strlen($prompt),
                'success' => $success,
                'streaming' => $isStreaming,
            ];
            if (!empty($usage)) {
                $eventData['usage'] = $usage;
            }
            if ($errorMessage) {
                $eventData['error'] = substr($errorMessage, 0, 1000); // Truncate error message
            }
            $event->EventData = json_encode($eventData);
            $event->write();
        } catch (\Exception $e) {
            /** @var LoggerInterface|null $logger */
            $logger = Injector::inst()->get(LoggerInterface::class, false); // false for optional
            if ($logger) {
                $logger->warning('Failed to record content generation event: ' . $e->getMessage(), [
                    'exception' => $e,
                    'dataObjectID' => $dataObjectID,
                    'dataObjectClass' => $dataObjectClass
                ]);
            }
        }
    }
}
