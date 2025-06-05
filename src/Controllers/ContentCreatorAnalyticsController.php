<?php

namespace KhalsaJio\ContentCreator\Controllers;

use Psr\Log\LoggerInterface;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use KhalsaJio\ContentCreator\Models\ContentCreationEvent;

/**
 * Controller for handling content creation analytics
 */
class ContentCreatorAnalyticsController extends Controller
{
    private static $url_segment = 'contentcreator';

    private static $url_handlers = [
        'POST analytics' => 'analytics',
        'GET report' => 'report',
    ];

    private static $allowed_actions = [
        'analytics',
        'report',
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
            // Ensure we have a current user
            $currentUser = Security::getCurrentUser();
            if (!$currentUser) {
                return $this->jsonResponse(['error' => 'User not authenticated'], 401);
            }

            // Record the event
            $event = ContentCreationEvent::create();
            $event->Type = $data['type'];
            $event->EventData = json_encode($data['data'] ?? []);
            $event->MemberID = $currentUser->ID;

            // If this is about a specific DataObject, link to it
            if (isset($data['data']['dataObjectID']) && isset($data['data']['dataObjectClass'])) {
                $dataObjectID = (int)$data['data']['dataObjectID'];
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
                ->error('Error recording analytics: ' . $e->getMessage());
            return $this->jsonResponse(['error' => 'Internal server error'], 500);
        }
    }


    /**
     * Generate an analytics report
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function report(HTTPRequest $request)
    {
        // Check if the user is authenticated
        if (!Security::getCurrentUser()) {
            // Redirect to login page if not authenticated
            return $this->redirect(Security::login_url());
        }

        // Only admins can view reports
        if (!Permission::check('ADMIN')) {
            return $this->jsonResponse(['error' => 'Permission denied'], 403);
        }

        $startDate = $request->getVar('start');
        $endDate = $request->getVar('end');

        $events = ContentCreationEvent::get();

        if ($startDate) {
            $events = $events->filter('Created:GreaterThanOrEqual', $startDate);
        }

        if ($endDate) {
            $events = $events->filter('Created:LessThanOrEqual', $endDate);
        }

        // Group by type
        $stats = [
            'total' => $events->count(),
            'by_type' => [],
            'by_user' => [],
            'by_dataobject' => [],
        ];

        $types = $events->column('Type');
        $types = array_count_values($types);

        foreach ($types as $type => $count) {
            $stats['by_type'][] = [
                'type' => $type,
                'count' => $count,
            ];
        }

        // Group by user
        $userEvents = $events->groupBy('MemberID');

        foreach ($userEvents as $memberID => $memberEvents) {
            if (!$memberID) {
                continue;
            }

            $member = DataObject::get_by_id(Member::class, $memberID);

            if (!$member) {
                continue;
            }

            $stats['by_user'][] = [
                'member_id' => $memberID,
                'name' => $member->getName(),
                'email' => $member->Email,
                'count' => count($memberEvents),
            ];
        }

        // Group by page
        $pageEvents = $events->filter('RelatedObjectID:GreaterThan', 0)->groupBy('RelatedObjectID');

        foreach ($pageEvents as $pageID => $pageEvents) {
            if (!$pageID) {
                continue;
            }

            $firstEvent = $pageEvents[0];

            if (!$firstEvent->RelatedObjectClass || !class_exists($firstEvent->RelatedObjectClass)) {
                continue;
            }

            $page = DataObject::get_by_id($firstEvent->RelatedObjectClass, $pageID);

            if (!$page) {
                continue;
            }

            $stats['by_dataobject'][] = [
                'dataobject_id' => $pageID,
                'title' => $page->Title,
                'type' => $page->ClassName,
                'count' => count($pageEvents),
            ];
        }

        return $this->jsonResponse($stats);
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
