<?php declare(strict_types = 0);

namespace Modules\MosicoySankeyFlow;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No data' => _('No data'),
                'No hosts' => _('No hosts'),
                'No problems' => _('No problems'),
                'Hosts' => _('Hosts'),
                'Severity' => _('Severity'),
                'Groups' => _('Groups')
            ]
        ];
    }
}
