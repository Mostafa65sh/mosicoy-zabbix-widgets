<?php

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-heatmap-container')
            ->addItem((new CDiv())->addClass('mosicoy-heatmap-toolbar'))
            ->addItem((new CDiv())->addClass('mosicoy-heatmap-scroll'))
            ->addItem((new CDiv())->addClass('mosicoy-heatmap-footer'))
    )
    ->setVar('columns', $data['columns'] ?? [])
    ->setVar('rows', $data['rows'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('truncated', $data['truncated'] ?? false)
    ->setVar('truncated_total', $data['truncated_total'] ?? 0)
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
