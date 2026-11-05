<?php declare(strict_types = 0);

namespace Modules\MosicoyProblemTimeline;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

    public function getTranslationStrings(): array {
        return [
            'class.widget.js' => [
                'No active problems' => _('No active problems'),
                'problems' => _('problems'),
                'NOW' => _('NOW'),
                'acknowledged' => _('Acknowledged')
            ]
        ];
    }
}
