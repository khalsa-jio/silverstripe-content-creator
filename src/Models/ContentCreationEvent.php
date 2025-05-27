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
        'DataObjectClassName' => 'Varchar(255)',
        'TokensUsed' => 'Int',
        'ProcessingTime' => 'Float',
        'Success' => 'Boolean',
    ];

    private static $has_one = [
        'Member' => Member::class,
        'DataObject' => DataObject::class,
    ];

    private static $indexes = [
        'Type' => true,
        'Created' => true,
    ];

    private static $default_sort = 'Created DESC';

    private static $summary_fields = [
        'Created.Nice' => 'Date/Time',
        'Type' => 'Event Type',
        'Member.Title' => 'User',
        'DataObjectClassName' => 'Data Object Type',
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
        switch ($this->Type) {
            case 'generation_started':
                return 'Generation started';
            case 'generation_completed':
                return 'Generation completed';
            case 'content_applied':
                return 'Content applied to item';
            case 'generation_error':
                return 'Generation error';
            default:
                return $this->Type;
        }
    }
    
    /**
     * Associate this event with a DataObject
     * 
     * @param DataObject $object The object to associate with this event
     * @return $this
     */
    public function forObject(DataObject $object)
    {
        $this->DataObjectID = $object->ID;
        $this->DataObjectClassName = get_class($object);
        
        return $this;
    }
    
    /**
     * Get the associated DataObject, correctly typed
     * 
     * @return DataObject|null
     */
    public function getRelatedObject()
    {
        if (!$this->DataObjectID || !$this->DataObjectClassName || !class_exists($this->DataObjectClassName)) {
            return null;
        }
        
        return DataObject::get_by_id($this->DataObjectClassName, $this->DataObjectID);
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
}
