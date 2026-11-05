<?php

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-rm-container')
            ->addItem((new CDiv())->addClass('mosicoy-rm-toolbar'))
            ->addItem((new CDiv())->addClass('mosicoy-rm-list'))
            ->addItem((new CDiv())->addClass('mosicoy-rm-footer'))
    )
    ->setVar('peers', $data['peers'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('debug', $data['debug'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
