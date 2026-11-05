<?php declare(strict_types = 0);

namespace Modules\MosicoyNetworkTopology;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No data' => _('No data'),
                'No hosts' => _('No hosts'),
                'All OK' => _('All OK'),
                'No problems' => _('No problems'),
                'problems' => _('problems')
            ]
        ];
    }
}
