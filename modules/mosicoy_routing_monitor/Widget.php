<?php declare(strict_types = 0);

namespace Modules\MosicoyRoutingMonitor;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No neighbors' => _('No neighbors'),
                'Established' => _('Established'),
                'BGP' => _('BGP'),
                'OSPF' => _('OSPF')
            ]
        ];
    }
}
