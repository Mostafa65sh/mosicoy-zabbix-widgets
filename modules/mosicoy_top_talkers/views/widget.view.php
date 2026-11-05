<?php

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-tt-container')
            ->addItem((new CDiv())->addClass('mosicoy-tt-toolbar'))
            ->addItem((new CDiv())->addClass('mosicoy-tt-list'))
            ->addItem((new CDiv())->addClass('mosicoy-tt-footer'))
    )
    ->setVar('talkers', $data['talkers'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('debug', $data['debug'] ?? [])
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
