<?php

/**
 * Mosicoy Calendar Heatmap widget form view.
 *
 * @var CView $this
 * @var array $data
 */

$form = new CWidgetFormView($data);

// Host groups
$groupids_field = array_key_exists('groupids', $data['fields'])
    ? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
    : null;

if ($groupids_field !== null) {
    $form->addField($groupids_field);
}

// Hosts (only in non-template dashboards)
if ($data['templateid'] === null && array_key_exists('hostids', $data['fields'])) {
    $hostids_view = new CWidgetFieldMultiSelectHostView($data['fields']['hostids']);

    if ($groupids_field !== null) {
        $hostids_view->setFilterPreselect([
            'id' => $groupids_field->getId(),
            'accept' => CMultiSelect::FILTER_PRESELECT_ACCEPT_ID,
            'submit_as' => 'groupid'
        ]);
    }

    $form->addField($hostids_view);
}

// Override host (only in template dashboards)
if ($data['templateid'] !== null && array_key_exists('override_hostid', $data['fields'])) {
    $form->addField(
        new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
    );
}

// Items
if (array_key_exists('items', $data['fields'])) {
    $form->addField(
        new CWidgetFieldPatternSelectItemView($data['fields']['items'])
    );
}

// Time period
if (array_key_exists('time_period', $data['fields'])) {
    $form->addField(
        (new CWidgetFieldTimePeriodView($data['fields']['time_period']))
            ->setDateFormat(ZBX_FULL_DATE_TIME)
            ->setFromPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
            ->setToPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
    );
}

// Aggregate
if (array_key_exists('aggregate', $data['fields'])) {
    $form->addField(
        new CWidgetFieldSelectView($data['fields']['aggregate'])
    );
}

// Color palette
if (array_key_exists('color_palette', $data['fields'])) {
    $form->addField(
        new CWidgetFieldSelectView($data['fields']['color_palette'])
    );
}

$form->show();
