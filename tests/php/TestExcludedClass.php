<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Test class that should be excluded from relationships
 */
class TestExcludedClass extends DataObject implements TestOnly
{
    private static $table_name = 'ContentCreator_TestExcluded';

    private static $db = [
        'ExcludedField1' => 'Varchar(255)',
        'ExcludedField2' => 'Text'
    ];
}
