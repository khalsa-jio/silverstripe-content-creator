<?php

namespace KhalsaJio\ContentCreator\Reports;

use SilverStripe\Reports\Report;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use KhalsaJio\ContentCreator\Models\ContentCreationEvent;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Member;
use SilverStripe\Control\Controller;
use SilverStripe\View\ArrayData;

/**
 * CMS report for content generation statistics
 */
class ContentGenerationReport extends Report
{
    /**
     * Report title
     *
     * @return string
     */
    public function title()
    {
        return _t(__CLASS__ . '.TITLE', 'Content Generation Statistics');
    }

    /**
     * Report description
     *
     * @return string
     */
    public function description()
    {
        return _t(
            __CLASS__ . '.DESCRIPTION',
            'Statistics and analytics for content generation using AI'
        );
    }

    /**
     * Only allow admins to view this report
     *
     * @param Member|null $member
     * @return bool
     */
    public function canView($member = null)
    {
        return Permission::checkMember($member, 'ADMIN');
    }

    /**
     * Get the report's fields
     *
     * @return FieldList
     */
    public function parameterFields()
    {
        $fields = FieldList::create();

        $dateFrom = DateField::create('DateFrom', 'From')
            ->setDescription('Filter events from this date');

        $dateTo = DateField::create('DateTo', 'To')
            ->setDescription('Filter events to this date');

        $typeField = DropdownField::create(
            'EventType',
            'Event Type',
            [
                '' => 'All Types',
                'generation_started' => 'Generation Started',
                'generation_completed' => 'Generation Completed',
                'content_applied' => 'Content Applied',
                'generation_error' => 'Generation Error'
            ]
        );

        $fields->push(
            FieldGroup::create(
                'Filter',
                $dateFrom,
                $dateTo,
                $typeField
            )->setTitle('Filter')
        );

        // Add some buttons
        $exportCSV = FormAction::create(
            'doExport',
            _t(__CLASS__ . '.EXPORTCSV', 'Export CSV')
        )->addExtraClass('btn btn-secondary');

        $fields->push(
            FieldGroup::create('Actions', $exportCSV)
                ->setTitle('Actions')
        );

        return $fields;
    }

    /**
     * Get the report columns
     *
     * @return array
     */
    public function columns()
    {
        return [
            'Created' => [
                'title' => 'Date/Time',
                'formatting' => function ($value, $item) {
                    return $item->dbObject('Created')->Nice();
                }
            ],
            'Type' => [
                'title' => 'Event Type',
                'formatting' => function ($value, $item) {
                    return $item->getDescription();
                }
            ],
            'Member.Title' => 'User',
            'RelatedObjectClass' => [
                'title' => 'Object Type',
                'formatting' => function ($value, $item) {
                    if ($item->RelatedObjectClass) {
                        $parts = explode('\\', $item->RelatedObjectClass);
                        return end($parts);
                    }
                    return null;
                }
            ],
            'TokensUsed' => 'Tokens Used',
            'ProcessingTime' => [
                'title' => 'Processing Time',
                'formatting' => function ($value, $item) {
                    if ($item->ProcessingTime) {
                        return number_format($item->ProcessingTime, 2) . 's';
                    }
                    return null;
                }
            ],
            'Success' => [
                'title' => 'Successful',
                'formatting' => function ($value, $item) {
                    return $item->Success ? 'Yes' : 'No';
                }
            ]
        ];
    }

    /**
     * Get the source records
     *
     * @param array $params
     * @param string $sort
     * @param string $limit
     * @return \SilverStripe\ORM\DataList
     */
    public function sourceRecords($params, $sort = null, $limit = null)
    {
        $events = ContentCreationEvent::get();

        if (isset($params['DateFrom']) && $params['DateFrom']) {
            $events = $events->filter('Created:GreaterThanOrEqual', $params['DateFrom']);
        }

        if (isset($params['DateTo']) && $params['DateTo']) {
            $events = $events->filter('Created:LessThanOrEqual', $params['DateTo'] . ' 23:59:59');
        }

        if (isset($params['EventType']) && $params['EventType']) {
            $events = $events->filter('Type', $params['EventType']);
        }

        return $events->sort('Created DESC');
    }

    /**
     * Add some statistics to the output
     *
     * @param array $params
     * @return \SilverStripe\Forms\FormField subclass
     */
    public function getReportField($params = [])
    {
        $field = parent::getReportField();

        // Get the filtered records
        $records = $this->sourceRecords($params);

        // Calculate some statistics
        $stats = [
            'TotalEvents' => $records->count(),
            'TotalTokens' => $records->sum('TokensUsed'),
            'AvgProcessingTime' => $records->avg('ProcessingTime'),
            'SuccessRate' => ($records->count() > 0)
                ? ($records->filter('Success', 1)->count() / $records->count() * 100)
                : 0
        ];

        // Generate chart data
        $eventsByDate = [];
        $tokensByDate = [];

        foreach ($records as $event) {
            $date = $event->dbObject('Created')->Format('Y-m-d');

            if (!isset($eventsByDate[$date])) {
                $eventsByDate[$date] = 0;
                $tokensByDate[$date] = 0;
            }

            $eventsByDate[$date]++;
            $tokensByDate[$date] += $event->TokensUsed;
        }

        $chartData = [
            'dates' => array_keys($eventsByDate),
            'events' => array_values($eventsByDate),
            'tokens' => array_values($tokensByDate)
        ];

        // Add a custom template that will include the stats
        $field->setTemplate('SilverStripe/ContentCreator/Reports/ContentGenerationReport');

        // Add the statistics to the field
        $field->setCustomParameters([
            'Statistics' => new ArrayData($stats),
            'ChartData' => json_encode($chartData)
        ]);

        return $field;
    }

    /**
     * Export the report data to CSV
     *
     * @param array $params
     * @return string CSV data
     */
    public function doExport($params)
    {
        $records = $this->sourceRecords($params);

        $headers = array_map(function ($col) {
            return is_array($col) ? $col['title'] : $col;
        }, $this->columns());

        $rows = [];
        $rows[] = '"' . implode('","', $headers) . '"';

        foreach ($records as $record) {
            $row = [];

            foreach ($this->columns() as $field => $info) {
                $value = $record;
                foreach (explode('.', $field) as $part) {
                    $value = $value->$part;
                }

                if (is_array($info) && isset($info['formatting'])) {
                    $value = $info['formatting']($value, $record);
                }

                $row[] = str_replace('"', '""', $value);
            }

            $rows[] = '"' . implode('","', $row) . '"';
        }

        $response = Controller::curr()->getResponse();
        $response->addHeader('Content-Type', 'text/csv');
        $response->addHeader('Content-Disposition', 'attachment; filename="content-generation-report.csv"');

        return implode("\n", $rows);
    }
}
