<?php

namespace KhalsaJio\ContentCreator\Extensions;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Core\Config\Config;

class ContentCreatorExtension extends Extension
{
    /**
     * Page types that should have the content creator button
     *
     * @config
     * @var array
     */
    private static $enabled_page_types = [];

    /**
     * Page types that should never have the content creator button
     *
     * @config
     * @var array
     */
    private static $excluded_page_types = [];

    /**
     * Update the CMS fields
     *
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        // Check if this page type should have the content creator
        if (!$this->shouldEnableContentCreator()) {
            return;
        }

        $fields->addFieldToTab(
            'Root.Main',
            LiteralField::create(
                'ContentCreatorModalContainer',
                '<div id="content-creator-modal" class="content-creator-modal"></div>'
            )
        );
    }

    /**
     * Update the CMS Actions
     *
     * @param FieldList $actions
     * @return void
     */
    public function updateCMSActions(FieldList $actions)
    {
        if (!$this->shouldEnableContentCreator()) {
            return;
        }

        $owner = $this->owner;

        $generateButton = FormAction::create(
            'doGenerateContentAI',
            _t('KhalsaJio\\ContentCreator\\Extensions\\ContentCreatorExtension.GENERATE_CONTENT_ACTION', 'AI Content')
        )
            ->setUseButtonTag(true)
            ->addExtraClass('btn btn-outline-info font-icon-rocket action_contentcreator')
            ->setAttribute('data-record-id', $owner->ID)
            ->setAttribute('data-record-class', $owner->ClassName);

        if ($actions->fieldByName('action_save')) {
            $actions->insertBefore('action_save', $generateButton);
        } else {
            $actions->push($generateButton);
        }
    }

    /**
     * Check if the content creator should be enabled for this page type
     *
     * @return bool
     */
    public function shouldEnableContentCreator()
    {
        $owner = $this->owner;

        // Check excluded types first
        $excludedTypes = Config::inst()->get(static::class, 'excluded_page_types');
        if (!empty($excludedTypes)) {
            foreach ($excludedTypes as $excludedType) {
                if (is_a($owner, $excludedType)) {
                    return false;
                }
            }
        }

        // Then check enabled types
        $enabledTypes = Config::inst()->get(static::class, 'enabled_page_types');
        if (!empty($enabledTypes)) {
            foreach ($enabledTypes as $enabledType) {
                if (is_a($owner, $enabledType)) {
                    return true;
                }
            }
            return false;
        }

        // If no enabled types specified, enable all (except excluded)
        return true;
    }
}
