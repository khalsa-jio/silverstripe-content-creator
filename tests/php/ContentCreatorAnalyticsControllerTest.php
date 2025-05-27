<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use KhalsaJio\ContentCreator\Models\ContentCreationEvent;
use KhalsaJio\ContentCreator\Controllers\ContentCreatorAnalyticsController;

/**
 * Test for ContentCreatorAnalyticsController
 */
class ContentCreatorAnalyticsControllerTest extends FunctionalTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'ContentCreatorAnalyticsControllerTest.yml';

    /**
     * @var bool
     */
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable analytics for the test
        Config::modify()->set(ContentCreatorAnalyticsController::class, 'enable_analytics', true);

        // Log in as admin
        $this->logInWithPermission('ADMIN');
    }

    /**
     * Test that analytics events are recorded correctly
     */
    public function testRecordAnalyticsEvent()
    {
        $controller = new ContentCreatorAnalyticsController();
        $eventData = [
            'type' => 'generation_started',
            'data' => [
                'pageID' => 1,
                'prompt' => 'Test prompt',
                'fields' => ['Title', 'Content']
            ]
        ];

        // Create fake request with event data
        $request = new \SilverStripe\Control\HTTPRequest(
            'POST',
            'analytics',
            [],
            [],
            json_encode($eventData)
        );

        // Set X-Requested-With header for AJAX request validation
        $request->addHeader('X-Requested-With', 'XMLHttpRequest');

        $response = $controller->analytics($request);

        // Check the response
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertTrue($responseData['success']);

        // Check that the event was recorded in the database
        $event = ContentCreationEvent::get()->filter([
            'Type' => 'generation_started',
            'MemberID' => Security::getCurrentUser()->ID
        ])->first();

        $this->assertNotNull($event);
        $this->assertEquals('generation_started', $event->Type);

        // Check that the event data was saved
        $eventDataDecoded = json_decode($event->EventData, true);
        $this->assertEquals('Test prompt', $eventDataDecoded['prompt']);
    }

    /**
     * Test that analytics are disabled when the config is set to false
     */
    public function testAnalyticsDisabled()
    {
        // Disable analytics
        Config::modify()->set(ContentCreatorAnalyticsController::class, 'enable_analytics', false);

        $controller = new ContentCreatorAnalyticsController();
        $eventData = [
            'type' => 'generation_completed',
            'data' => [
                'pageID' => 1,
                'success' => true,
                'tokens' => 1024
            ]
        ];

        // Create fake request with event data
        $request = new \SilverStripe\Control\HTTPRequest(
            'POST',
            'analytics',
            [],
            [],
            json_encode($eventData)
        );

        // Set X-Requested-With header for AJAX request validation
        $request->addHeader('X-Requested-With', 'XMLHttpRequest');

        // Call the analytics method
        $response = $controller->analytics($request);

        // Check the response
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertTrue($responseData['success']);
        $this->assertEquals('Analytics disabled', $responseData['message']);

        // Check that no event was recorded
        $count = ContentCreationEvent::get()->filter([
            'Type' => 'generation_completed'
        ])->count();

        $this->assertEquals(0, $count);
    }

    /**
     * Test that only admins can view reports
     */
    public function testReportPermissions()
    {
        // First check with admin permissions
        $response = $this->get('/admin/contentcreator/report');
        $this->assertEquals(200, $response->getStatusCode());

        // Now logout and try again
        $this->logOut();

        $response = $this->get('/admin/contentcreator/report');
        // Should redirect to login
        $this->assertEquals(302, $response->getStatusCode());

        // Log in as a non-admin
        $member = $this->objFromFixture(Member::class, 'user');
        $this->logInAs($member);

        $response = $this->get('/admin/contentcreator/report');
        // Should still be forbidden
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Test that invalid requests are rejected
     */
    public function testInvalidRequest()
    {
        $controller = new ContentCreatorAnalyticsController();

        // Test with missing X-Requested-With header
        $request = new \SilverStripe\Control\HTTPRequest(
            'POST',
            'analytics',
            [],
            [],
            json_encode(['type' => 'test'])
        );

        $response = $controller->analytics($request);
        $this->assertEquals(400, $response->getStatusCode());

        // Test with missing type but with X-Requested-With header
        $request = new \SilverStripe\Control\HTTPRequest(
            'POST',
            'analytics',
            [],
            [],
            json_encode(['data' => []])
        );

        // Add X-Requested-With header
        $request->addHeader('X-Requested-With', 'XMLHttpRequest');

        $response = $controller->analytics($request);
        $this->assertEquals(400, $response->getStatusCode());
    }
}
