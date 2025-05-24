<?php

namespace KhalsaJio\ContentCreator\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use KhalsaJio\ContentCreator\Services\ContentGeneratorService;
use SilverStripe\Security\SecurityToken;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Control\HTTPResponse;

/**
 * Controller for handling content generation requests
 */
class ContentCreatorController extends Controller
{
    private static $url_segment = 'contentcreator';
    
    /**
     * Allowed actions for this controller
     */
    private static $allowed_actions = [
        'generate',
        'getPageStructure', 
        'applyContent',
        'debug',  // Add a debug action
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

        $pageId = $request->postVar('pageID');
        $prompt = $request->postVar('prompt');
        
        if (!$pageId || !$prompt) {
            return $this->jsonResponse(['error' => 'Missing required parameters'], 400);
        }
        
        $page = DataObject::get_by_id($pageId);
        if (!$page || !$page->exists()) {
            return $this->jsonResponse(['error' => 'Page not found'], 404);
        }
        
        try {
            $generator = Injector::inst()->get(ContentGeneratorService::class);
            $content = $generator->generateContent($page, $prompt);
            
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

        $pageId = $request->getVar('pageID');
        
        if (!$pageId) {
            return $this->jsonResponse(['error' => 'Missing required parameters'], 400);
        }
        
        $page = DataObject::get_by_id($pageId);
        if (!$page || !$page->exists()) {
            return $this->jsonResponse(['error' => 'Page not found'], 404);
        }
        
        try {
            $generator = Injector::inst()->get(ContentGeneratorService::class);
            $structure = $generator->getPageFieldStructure($page);
            
            return $this->jsonResponse([
                'success' => true,
                'structure' => $structure
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'error' => $e->getMessage()
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

        $pageId = $request->postVar('pageID');
        $contentData = $request->postVar('content');

        if (!$pageId || !$contentData) {
            return $this->jsonResponse(['error' => 'Missing required parameters'], 400);
        }

        if (is_string($contentData)) {
            $contentData = json_decode($contentData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->jsonResponse(['error' => 'Invalid content data format'], 400);
            }
        }

        $page = DataObject::get_by_id($pageId);
        if (!$page || !$page->exists()) {
            return $this->jsonResponse(['error' => 'Page not found'], 404);
        }

        try {
            // Apply the content using the Populate module if available
            if (class_exists('SilverStripe\\Populate\\Populate')) {
                $populator = \SilverStripe\Populate\Populate::create();
                // Use the Populate module to fill the page
                $populator->populateObject($page, $contentData);
            } else {
                // Fallback: manually populate fields if Populate module is not available
                $this->populatePageFields($page, $contentData);
            }

            return $this->jsonResponse([
                'success' => true,
                'message' => 'Content successfully applied to the page'
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
     * @param DataObject $page
     * @param array $data
     */
    protected function populatePageFields(DataObject $page, array $data)
    {
        foreach ($data as $fieldName => $value) {
            // Skip fields that don't exist on the page
            if (!$page->hasField($fieldName) && !$page->hasMethod("set$fieldName")) {
                continue;
            }
            
            // Set the field value
            $page->$fieldName = $value;
        }
        
        $page->write();
    }

    /**
     * Validate that the request is valid
     *
     * @param HTTPRequest $request
     * @return bool
     */
    protected function validateRequest(HTTPRequest $request)
    {
        // Check CSRF token
        $csrfToken = $request->getHeader('X-SecurityToken');
        if (!SecurityToken::inst()->check($csrfToken)) {
            return false;
        }
        
        // Check permissions
        if (!Permission::check('CMS_ACCESS_CMSMain')) {
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
}
