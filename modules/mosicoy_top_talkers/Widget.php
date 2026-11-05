<?php declare(strict_types = 0);

namespace Modules\MosicoyTopTalkers;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No data' => _('No data'),
                'No interfaces' => _('No interfaces'),
                'Mbps' => _('Mbps'),
                'Gbps' => _('Gbps')
            ]
        ];
    }
}
