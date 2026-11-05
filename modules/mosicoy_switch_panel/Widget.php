<?php declare(strict_types = 0);

namespace Modules\MosicoySwitchPanel;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No ports' => _('No ports'),
                'No switches' => _('No switches'),
                'up' => _('up'),
                'down' => _('down'),
                'ports' => _('ports')
            ]
        ];
    }
}
