<?php

(new CWidgetView($data))
    ->addItem(
        (new CDiv())->addClass('mosicoy-switch-panel-container')
    )
    ->setVar('hosts', $data['hosts'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('sparkline_debug', $data['sparkline_debug'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
