<?php

/**
 * Mosicoy Problem Timeline widget view.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetView($data))
    ->addItem(
        (new CDiv())
            ->addClass('mosicoy-timeline-container')
            ->addItem((new CDiv())->addClass('mosicoy-timeline-toolbar'))
            ->addItem((new CDiv())->addClass('mosicoy-timeline-body'))
            ->addItem((new CDiv())->addClass('mosicoy-timeline-footer'))
    )
    ->setVar('problems', $data['problems'] ?? [])
    ->setVar('summary', $data['summary'] ?? [])
    ->setVar('now', $data['now'] ?? time())
    ->setVar('window_from', $data['window_from'] ?? (time() - 21600))
    ->setVar('window_to', $data['window_to'] ?? time())
    ->setVar('fields_values', $data['fields_values'] ?? [])
    ->show();
