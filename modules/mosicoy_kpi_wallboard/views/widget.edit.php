<?php

/**
 * Mosicoy KPI Wallboard widget form view.
 *
 * @var CView $this
 * @var array $data
 */

$form = new CWidgetFormView($data);

$groupids_field = array_key_exists('groupids', $data['fields'])
    ? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
    : null;

if ($groupids_field !== null) {
    $form->addField($groupids_field);
}

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

if ($data['templateid'] !== null && array_key_exists('override_hostid', $data['fields'])) {
    $form->addField(
        new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
    );
}

if (array_key_exists('items', $data['fields'])) {
    $form->addField(new CWidgetFieldPatternSelectItemView($data['fields']['items']));
}

if (array_key_exists('threshold_rules', $data['fields'])) {
    $form->addField(new CWidgetFieldTextAreaView($data['fields']['threshold_rules']));
}

if (array_key_exists('cell_style', $data['fields'])) {
    $form->addField(new CWidgetFieldSelectView($data['fields']['cell_style']));
}

foreach (['show_sparkline', 'show_delta', 'show_stats', 'sort_by_severity'] as $f) {
    if (array_key_exists($f, $data['fields'])) {
        $form->addField(new CWidgetFieldCheckBoxView($data['fields'][$f]));
    }
}

if (array_key_exists('max_items', $data['fields'])) {
    $form->addField(new CWidgetFieldIntegerBoxView($data['fields']['max_items']));
}

$form->show();
