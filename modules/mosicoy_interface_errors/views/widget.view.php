<?php

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-ie-container')
            ->addItem((new CDiv())->addClass('mosicoy-ie-toolbar'))
            ->addItem((new CDiv())->addClass('mosicoy-ie-list'))
            ->addItem((new CDiv())->addClass('mosicoy-ie-footer'))
    )
    ->setVar('interfaces', $data['interfaces'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('debug', $data['debug'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
