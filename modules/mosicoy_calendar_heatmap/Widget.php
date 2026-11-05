<?php declare(strict_types = 0);

namespace Modules\MosicoyCalendar;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No data' => _('No data'),
                'No historical data' => _('No historical data'),
                'Error' => _('Error')
            ]
        ];
    }
}
