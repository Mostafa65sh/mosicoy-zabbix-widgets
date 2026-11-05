<?php

$form = new CWidgetFormView($data);

if (array_key_exists('links', $data['fields'])) {
    $form->addField(new CWidgetFieldTextAreaView($data['fields']['links']));
}

if (array_key_exists('layout', $data['fields'])) {
    $form->addField(new CWidgetFieldSelectView($data['fields']['layout']));
}

foreach (['show_link_labels', 'size_by_problems'] as $f) {
    if (array_key_exists($f, $data['fields'])) {
        $form->addField(new CWidgetFieldCheckBoxView($data['fields'][$f]));
    }
}

if (array_key_exists('max_nodes', $data['fields'])) {
    $form->addField(new CWidgetFieldIntegerBoxView($data['fields']['max_nodes']));
}

$form->show();
