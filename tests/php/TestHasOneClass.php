<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Test class for has_one relationships
 */
class TestHasOneClass extends DataObject implements TestOnly
{
    private static $table_name = 'ContentCreator_TestHasOne';

    private static $db = [
        'Title' => 'Varchar(255)',
        'Content' => 'Text',
        'Reference' => 'Varchar(50)'
    ];
}
