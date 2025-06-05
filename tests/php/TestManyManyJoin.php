<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Test join table for many_many relationships
 */
class TestManyManyJoin extends DataObject implements TestOnly
{
    private static $table_name = 'ContentCreator_TestManyManyJoin';

    private static $db = [
        'SortOrder' => 'Int'
    ];

    private static $has_one = [
        'Parent' => TestElement::class,
        'Child' => TestManyManyClass::class
    ];
}
