<?php

namespace KhalsaJio\ContentCreator\Models;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Model for tracking content creation events
 */
class ContentCreationEvent extends DataObject
{
    private static $table_name = 'ContentCreationEvent';

    private static $db = [
        'Type' => 'Varchar(100)',
        'EventData' => 'Text',
        'RelatedObjectID' => 'Int',
        'RelatedObjectClass' => 'Varchar(255)',
        'TokensUsed' => 'Int',
        'ProcessingTime' => 'Float',
        'Success' => 'Boolean',
    ];

    private static $has_one = [
        'Member' => Member::class,
    ];

    private static $indexes = [
        'Type' => true,
        'Created' => true,
        'RelatedObjectID' => true,
        'RelatedObjectClass' => true,
    ];

    private static $default_sort = 'Created DESC';

    private static $summary_fields = [
        'Created.Nice' => 'Date/Time',
        'Description' => 'Event Type',
        'Member.Title' => 'User',
        'RelatedObjectClass' => 'Data Object Type',
        'RelatedObjectTitle' => 'Related Object',
        'Success' => 'Successful',
    ];

    /**
     * Only admins can view analytics events
     *
     * @param Member|null $member
     * @return bool
     */
    public function canView($member = null)
    {
        return Permission::checkMember($member, 'ADMIN');
    }

    /**
     * Events can't be edited
     *
     * @param Member|null $member
     * @return bool
     */
    public function canEdit($member = null)
    {
        return false;
    }

    /**
     * Events can't be deleted individually (use reports for bulk actions)
     *
     * @param Member|null $member
     * @return bool
     */
    public function canDelete($member = null)
    {
        return Permission::checkMember($member, 'ADMIN');
    }

    /**
     * Events are created by the system, not users
     *
     * @param Member|null $member
     * @param array $context
     * @return bool
     */
    public function canCreate($member = null, $context = [])
    {
        return false;
    }

    /**
     * Get the decoded event data
     *
     * @return array|null
     */
    public function getDecodedEventData()
    {
        if ($this->EventData) {
            return json_decode($this->EventData, true);
        }

        return null;
    }

    /**
     * Get a human-readable description of the event
     *
     * @return string
     */
    public function getDescription()
    {
        $words = explode('_', $this->Type);
        $titleCaseWords = array_map(function ($word) {
            return ucfirst($word);
        }, $words);

        return implode(' ', $titleCaseWords);
    }

    /**
     * Associate this event with a DataObject
     *
     * @param DataObject $object The object to associate with this event
     * @return $this
     */
    public function forObject(DataObject $object)
    {
        $this->RelatedObjectID = $object->ID;
        $this->RelatedObjectClass = get_class($object);

        return $this;
    }

    /**
     * Get the associated DataObject, correctly typed
     *
     * @return DataObject|null
     */
    public function getRelatedObject()
    {
        if (!$this->RelatedObjectID || !$this->RelatedObjectClass || !class_exists($this->RelatedObjectClass)) {
            return null;
        }

        return DataObject::get_by_id($this->RelatedObjectClass, $this->RelatedObjectID);
    }

    /**
     * Get the title of the associated DataObject if available
     *
     * @return string
     */
    public function getRelatedObjectTitle()
    {
        $object = $this->getRelatedObject();

        if (!$object) {
            return 'Unknown';
        }

        // Try to get a title using common properties
        foreach (['Title', 'Name', 'FullName'] as $field) {
            if ($object->hasField($field)) {
                return $object->$field;
            }
        }

        // Fallback to class and ID
        return $object->ClassName . ' #' . $object->ID;
    }

    /**
     * Get a shorter class name for display
     *
     * @return string
     */
    public function getRelatedObjectShortClass()
    {
        if (!$this->RelatedObjectClass) {
            return '';
        }

        $parts = explode('\\', $this->RelatedObjectClass);
        return end($parts);
    }
}
