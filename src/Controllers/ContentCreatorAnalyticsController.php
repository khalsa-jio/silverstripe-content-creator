<?php

namespace KhalsaJio\ContentCreator\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Member;
use SilverStripe\Security\SecurityToken;
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
            // Record the event
            $event = ContentCreationEvent::create();
            $event->Type = $data['type'];
            $event->EventData = json_encode($data['data'] ?? []);
            $event->MemberID = Security::getCurrentUser()->ID;

            // If this is about a specific page, link to it
            if (isset($data['data']['pageID'])) {
                $pageID = $data['data']['pageID'];
                $page = DataObject::get_by_id($pageID);

                if ($page && $page->exists()) {
                    $event->PageID = $page->ID;
                    $event->PageClass = get_class($page);
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
     * Generate an analytics report
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function report(HTTPRequest $request)
    {
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
            'by_page' => [],
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
            if (!$memberID) continue;

            $member = DataObject::get_by_id(Member::class, $memberID);

            if (!$member) continue;

            $stats['by_user'][] = [
                'member_id' => $memberID,
                'name' => $member->getName(),
                'email' => $member->Email,
                'count' => count($memberEvents),
            ];
        }

        // Group by page
        $pageEvents = $events->filter('PageID:GreaterThan', 0)->groupBy('PageID');

        foreach ($pageEvents as $pageID => $pageEvents) {
            if (!$pageID) continue;

            $firstEvent = $pageEvents[0];

            if (!$firstEvent->PageClass || !class_exists($firstEvent->PageClass)) continue;

            $page = DataObject::get_by_id($firstEvent->PageClass, $pageID);

            if (!$page) continue;

            $stats['by_page'][] = [
                'page_id' => $pageID,
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
}
