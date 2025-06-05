<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Test class for many_many relationships
 */
class TestManyManyClass extends DataObject implements TestOnly
{
    private static $table_name = 'ContentCreator_TestManyMany';

    private static $db = [
        'Code' => 'Varchar(100)',
        'Value' => 'Varchar(255)',
        'SortOrder' => 'Int'
    ];

    private static $belongs_many_many = [
        'Elements' => TestElement::class
    ];
}
