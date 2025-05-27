<?php

namespace KhalsaJio\ContentCreator\Admin;

use SilverStripe\Admin\ModelAdmin;
use KhalsaJio\ContentCreator\Models\ContentCreationEvent;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FormAction;

/**
 * Admin interface for viewing content creation analytics
 */
class ContentCreatorAdmin extends ModelAdmin
{
    private static $managed_models = [
        ContentCreationEvent::class,
    ];

    private static $url_segment = 'content-creator';

    private static $menu_title = 'Content Creator';

    private static $menu_icon_class = 'font-icon-block-content';

    /**
     * Update the grid field config to add custom filters and exporters
     *
     * @param int $id
     * @param \SilverStripe\Forms\FieldList $fields
     * @return \SilverStripe\Forms\Form
     */
    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);

        if ($this->modelClass === ContentCreationEvent::class) {
            $gridField = $form->Fields()->fieldByName($this->sanitiseClassName($this->modelClass));

            // Add custom date filtering
            $dateFrom = DateField::create('q[DateFrom]', 'From');
            $dateTo = DateField::create('q[DateTo]', 'To');

            $typeFilter = DropdownField::create(
                'q[Type]',
                'Event Type',
                [
                    '' => 'All Types',
                    'generation_started' => 'Generation Started',
                    'generation_completed' => 'Generation Completed',
                    'content_applied' => 'Content Applied',
                    'generation_error' => 'Generation Error'
                ]
            );

            // Add a reset button
            $resetAction = FormAction::create('resetFilter', 'Reset Filter')
                ->setUseButtonTag(true)
                ->addExtraClass('btn-secondary');

            // Add a search button
            $searchAction = FormAction::create('doFilter', 'Search')
                ->setUseButtonTag(true)
                ->addExtraClass('btn-primary')
                ->setForm($form);

            // Group the filters
            $filterGroup = FieldGroup::create(
                'Filter',
                $dateFrom,
                $dateTo,
                $typeFilter,
                $searchAction,
                $resetAction
            )->setTitle('Filter')->addExtraClass('stacked');

            // Insert the filter group before the GridField in the form's FieldList
            $form->Fields()->insertBefore($this->sanitiseClassName($this->modelClass), $filterGroup);

            // Add export functionality with more fields
            $exportColumns = [
                'Created' => 'Date/Time',
                'Type' => 'Event Type',
                'Member.Title' => 'User',
                'PageClass' => 'Page Type',
                'TokensUsed' => 'Tokens Used',
                'ProcessingTime' => 'Processing Time (s)',
                'Success' => 'Successful',
            ];

            $exportButton = $gridField->getConfig()->getComponentByType(GridFieldExportButton::class);
            if ($exportButton) {
                $exportButton->setExportColumns($exportColumns);
            }
        }

        return $form;
    }

    /**
     * Apply custom filtering for the date range
     *
     * @param \SilverStripe\ORM\SS_List $list
     * @return \SilverStripe\ORM\SS_List
     */
    public function getList()
    {
        $list = parent::getList();

        $params = $this->request->requestVars();

        if (isset($params['q']['DateFrom']) && $params['q']['DateFrom']) {
            $list = $list->filter('Created:GreaterThanOrEqual', $params['q']['DateFrom']);
        }

        if (isset($params['q']['DateTo']) && $params['q']['DateTo']) {
            $list = $list->filter('Created:LessThanOrEqual', $params['q']['DateTo'] . ' 23:59:59');
        }

        if (isset($params['q']['Type']) && $params['q']['Type']) {
            $list = $list->filter('Type', $params['q']['Type']);
        }

        return $list;
    }

    /**
     * Reset all filters
     */
    public function resetFilter($data = null, $form = null)
    {
        return $this->redirect($this->Link());
    }
    
    /**
     * Apply the filters
     */
    public function doFilter($data, $form = null)
    {
        $queryParams = [];
        if (isset($data['q']) && is_array($data['q'])) {
            foreach ($data['q'] as $key => $value) {
                if (!empty($value)) {
                    $queryParams['q[' . $key . ']'] = $value;
                }
            }
        }

        return $this->redirect($this->Link() . '?' . http_build_query($queryParams));
    }
}
