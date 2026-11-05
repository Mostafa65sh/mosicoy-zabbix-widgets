<?php declare(strict_types = 0);

namespace Modules\MosicoyGaugeCluster;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No items' => _('No items'),
                'No data' => _('No data'),
                'LIVE' => _('LIVE'),
                'STALE' => _('STALE'),
                'No items matched' => _('No items matched')
            ]
        ];
    }
}
