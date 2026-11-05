<?php declare(strict_types = 0);

namespace Modules\MosicoyInterfaceErrors;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No data' => _('No data'),
                'All clean' => _('All clean'),
                'errors' => _('errors'),
                'discards' => _('discards')
            ]
        ];
    }
}
