<?php declare(strict_types = 0);

namespace Modules\MosicoyHeatmapMatrix;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No items' => _('No items'),
                'No data' => _('No data'),
                'No items matched' => _('No items matched')
            ]
        ];
    }
}
