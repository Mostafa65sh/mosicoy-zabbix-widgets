<?php declare(strict_types = 0);

namespace Modules\MosicoyRadarCompare;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No data' => _('No data'),
                'No items matched' => _('No items matched'),
                'No series' => _('No series'),
                'compared' => _('compared')
            ]
        ];
    }
}
