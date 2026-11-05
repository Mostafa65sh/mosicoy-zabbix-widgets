<?php

/**
 * Mosicoy Sankey Flow widget view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-sankey-container')
            ->addItem((new CDiv())->addClass('mosicoy-sankey-chart'))
            ->addItem((new CDiv())->addClass('mosicoy-sankey-footer'))
    )
    ->setVar('nodes', $data['nodes'] ?? [])
    ->setVar('links', $data['links'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->setVar('truncated', $data['truncated'] ?? false)
    ->setVar('truncated_total', $data['truncated_total'] ?? 0)
    ->show();
