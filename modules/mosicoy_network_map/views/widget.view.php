<?php

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-netmap-container')
            ->addItem((new CDiv())->addClass('mosicoy-netmap-chart'))
            ->addItem((new CDiv())->addClass('mosicoy-netmap-footer'))
    )
    ->setVar('nodes', $data['nodes'] ?? [])
    ->setVar('links', $data['links'] ?? [])
    ->setVar('debug', $data['debug'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
