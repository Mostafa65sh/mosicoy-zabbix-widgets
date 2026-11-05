<?php declare(strict_types = 0);

namespace Modules\MosicoyStatusGrid;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No hosts' => _('No hosts'),
                'No data' => _('No data'),
                'OK' => _('OK'),
                'WARN' => _('WARN'),
                'CRIT' => _('CRIT'),
                'problems' => _('problems')
            ]
        ];
    }
}
