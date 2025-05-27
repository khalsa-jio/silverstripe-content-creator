<?php

namespace KhalsaJio\ContentCreator\Extensions;

use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Control\Controller;

class ContentCreatorGridFieldItemRequestExtension extends GridFieldDetailForm_ItemRequest
{
    private static $allowed_actions = [
        'contentcreator'
    ];

    /**
     * Add the content creator action to the GridField item actions
     */
    public function updateFormActions(&$actions)
    {
        $record = $this->record;

        // Check if content creator should be enabled for this page type
        if (!$record || !$record->hasExtension(ContentCreatorExtension::class)) {
            return;
        }

        // Create instance of the extension to check if it should be enabled
        $extension = Injector::inst()->create(ContentCreatorExtension::class);
        $extension->setOwner($record);
        if (!$extension->shouldEnableContentCreator()) {
            $extension->clearOwner();
            return;
        }
        $extension->clearOwner();

        // Add the content creator button
        $contentCreatorAction = GridField_FormAction::create(
            $this->gridField,
            'contentcreator' . $record->ID,
            _t('KhalsaJio\ContentCreator\Extensions\ContentCreatorExtension.GENERATE_CONTENT_SHORT', 'AI Content'),
        )
            ->addExtraClass('btn btn-outline-info font-icon-magic action_contentcreator')
            ->setAttribute('data-record-class', $record->ClassName)
            ->setAttribute('data-record-id', $record->ID)
            ->setAttribute('type', 'button');

        $actions->push($contentCreatorAction);
    }

    /**
     * Handle the content creator action
     */
    public function contentcreator($data, $form)
    {
        return Controller::curr()->redirectBack();
    }
}
