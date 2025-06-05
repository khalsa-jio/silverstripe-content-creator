<?php

namespace KhalsaJio\ContentCreator\Tests;

use SilverStripe\Dev\TestOnly;
use DNADesign\Elemental\Models\BaseElement;

/**
 * Test element class to use for relationship configuration testing
 */
class TestElement extends BaseElement implements TestOnly
{
    private static $table_name = 'ContentCreator_TestElement';

    private static $db = [
        'TestContent' => 'Text',
        'TestField' => 'Varchar(255)'
    ];

    private static $has_one = [
        'TestHasOne' => TestHasOneClass::class,
        'ExcludedHasOne' => TestExcludedClass::class,
        'SpecificExcludedHasOne' => TestHasOneClass::class
    ];

    private static $has_many = [
        'TestHasMany' => TestHasManyClass::class,
        'ExcludedHasMany' => TestExcludedClass::class
    ];

    private static $many_many = [
        'TestManyMany' => [
            'through' => TestManyManyJoin::class,
            'from' => 'Parent',
            'to' => 'Child'
        ],
        'ExcludedManyMany' => [
            'through' => TestManyManyJoin::class,
            'from' => 'Parent',
            'to' => 'Child'
        ]
    ];

    public function getType()
    {
        return 'Test Element';
    }
}
