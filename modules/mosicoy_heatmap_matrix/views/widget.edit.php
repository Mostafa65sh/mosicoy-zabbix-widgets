<?php

/**
 * Mosicoy Heatmap Matrix widget form view.
 *
 * @var CView $this
 * @var array $data
 */

$form = new CWidgetFormView($data);

$groupids_field = array_key_exists('groupids', $data['fields'])
    ? new CWidgetFieldMultiSelectGroupView($data['fields']['groupids'])
    : null;

if ($groupids_field !== null) $form->addField($groupids_field);

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
    $form->addField(new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid']));
}

if (array_key_exists('columns', $data['fields'])) {
    $form->addField(new CWidgetFieldTextAreaView($data['fields']['columns']));
}

foreach (['color_mode', 'sort_by'] as $f) {
    if (array_key_exists($f, $data['fields'])) {
        $form->addField(new CWidgetFieldSelectView($data['fields'][$f]));
    }
}

foreach (['show_values', 'compact'] as $f) {
    if (array_key_exists($f, $data['fields'])) {
        $form->addField(new CWidgetFieldCheckBoxView($data['fields'][$f]));
    }
}

if (array_key_exists('max_hosts', $data['fields'])) {
    $form->addField(new CWidgetFieldIntegerBoxView($data['fields']['max_hosts']));
}

$form->show();
