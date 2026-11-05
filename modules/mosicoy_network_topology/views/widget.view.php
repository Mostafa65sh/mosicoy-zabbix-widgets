<?php

/**
 * Mosicoy Network Topology widget view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-topology-container')
            ->addItem((new CDiv())->addClass('mosicoy-topology-chart'))
            ->addItem((new CDiv())->addClass('mosicoy-topology-footer'))
    )
    ->setVar('nodes', $data['nodes'] ?? [])
    ->setVar('links', $data['links'] ?? [])
    ->setVar('categories', $data['categories'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->setVar('truncated', $data['truncated'] ?? false)
    ->setVar('truncated_total', $data['truncated_total'] ?? 0)
    ->show();
