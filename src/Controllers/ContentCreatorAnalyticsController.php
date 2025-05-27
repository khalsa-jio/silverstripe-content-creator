<?php

namespace KhalsaJio\ContentCreator\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Config;
use KhalsaJio\ContentCreator\Models\ContentCreationEvent;
use SilverStripe\Security\Security;
use SilverStripe\ORM\DataObject;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling content creation analytics
 */
class ContentCreatorAnalyticsController extends Controller
{
    private static $url_segment = 'contentcreator';

    private static $url_handlers = [
        'POST analytics' => 'analytics',
    ];

    private static $allowed_actions = [
        'analytics',
    ];

    /**
     * Record an analytics event
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function analytics(HTTPRequest $request)
    {
        if (!$this->validateRequest($request)) {
            return $this->jsonResponse(['error' => 'Invalid request'], 400);
        }

        // Get JSON data from request
        $data = json_decode($request->getBody(), true);

        if (!$data || !isset($data['type'])) {
            return $this->jsonResponse(['error' => 'Invalid event data'], 400);
        }

        // Check if analytics are enabled
        if (!Config::inst()->get(ContentCreatorAnalyticsController::class, 'enable_analytics')) {
            return $this->jsonResponse(['success' => true, 'message' => 'Analytics disabled']);
        }

        try {
            // Record the event
            $event = ContentCreationEvent::create();
            $event->Type = $data['type'];
            $event->EventData = json_encode($data['data'] ?? []);
            $event->MemberID = Security::getCurrentUser()->ID;

            // If this is about a specific DataObject, link to it
            if (isset($data['data']['dataObjectID']) && isset($data['data']['dataObjectClass'])) {
                $dataObjectID = $data['data']['dataObjectID'];
                $dataObjectClass = $data['data']['dataObjectClass'];

                // Ensure the class exists and is a DataObject
                if (class_exists($dataObjectClass) && is_subclass_of($dataObjectClass, DataObject::class)) {
                    $dataObject = DataObject::get_by_id($dataObjectClass, $dataObjectID);

                    if ($dataObject && $dataObject->exists()) {
                        $event->forObject($dataObject);
                    }
                }
            }

            $event->write();

            return $this->jsonResponse(['success' => true]);
        } catch (\Exception $e) {
            Injector::inst()->get(LoggerInterface::class)
                ->debug('Error recording analytics: ' . $e->getMessage());
            return $this->jsonResponse(['success' => true]);
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
}
