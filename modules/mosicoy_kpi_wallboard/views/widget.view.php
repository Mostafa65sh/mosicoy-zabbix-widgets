<?php

/**
 * Mosicoy KPI Wallboard widget view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetView($data))
    ->addItem(
        (new CDiv())->addClass('mosicoy-kpi-container')
    )
    ->setVar('items', $data['items'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
