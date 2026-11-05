<?php declare(strict_types = 0);

namespace Modules\MosicoyNetworkMap;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No data' => _('No data'),
                'No links defined' => _('No links defined'),
                'OK' => _('OK')
            ]
        ];
    }
}
