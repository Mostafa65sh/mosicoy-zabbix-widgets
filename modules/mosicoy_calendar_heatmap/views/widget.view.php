<?php

/**
 * Mosicoy Calendar Heatmap widget view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-calendar-container')
            ->addItem(
                (new CDiv())
                    ->addClass('mosicoy-calendar-chart')
            )
    )
    ->setVar('history', $data['history'] ?? [])
    ->setVar('items_meta', $data['items_meta'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->setVar('debug', $data['debug'] ?? [])
    ->show();
