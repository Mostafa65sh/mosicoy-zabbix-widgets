<?php

/**
 * Mosicoy Status Grid widget view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-status-container')
    )
    ->setVar('hosts', $data['hosts'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->setVar('truncated', $data['truncated'] ?? false)
    ->setVar('truncated_total', $data['truncated_total'] ?? 0)
    ->show();
