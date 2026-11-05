<?php

/**
 * Mosicoy Gauge Cluster widget form view.
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

foreach (['cell_style', 'color_mode'] as $f) {
    if (array_key_exists($f, $data['fields'])) {
        $form->addField(new CWidgetFieldSelectView($data['fields'][$f]));
    }
}

if (array_key_exists('fixed_max', $data['fields'])) {
    $form->addField(new CWidgetFieldIntegerBoxView($data['fields']['fixed_max']));
}

if (array_key_exists('show_delta', $data['fields'])) {
    $form->addField(new CWidgetFieldCheckBoxView($data['fields']['show_delta']));
}

if (array_key_exists('max_items', $data['fields'])) {
    $form->addField(new CWidgetFieldIntegerBoxView($data['fields']['max_items']));
}

$form->show();
