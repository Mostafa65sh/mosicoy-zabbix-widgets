<?php

/**
 * Mosicoy Radar Compare widget view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-radar-container')
            ->addItem((new CDiv())->addClass('mosicoy-radar-chart'))
            ->addItem((new CDiv())->addClass('mosicoy-radar-footer'))
    )
    ->setVar('indicators', $data['indicators'] ?? [])
    ->setVar('series', $data['series'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->setVar('truncated', $data['truncated'] ?? false)
    ->setVar('truncated_total', $data['truncated_total'] ?? 0)
    ->show();
