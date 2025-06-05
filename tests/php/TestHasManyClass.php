<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Test class for has_many relationships
 */
class TestHasManyClass extends DataObject implements TestOnly
{
    private static $table_name = 'ContentCreator_TestHasMany';

    private static $db = [
        'Name' => 'Varchar(255)',
        'Description' => 'Text',
        'Priority' => 'Int'
    ];

    private static $has_one = [
        'Element' => TestElement::class
    ];
}
