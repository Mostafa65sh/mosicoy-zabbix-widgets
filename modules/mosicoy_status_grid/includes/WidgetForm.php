<?php declare(strict_types = 0);

namespace Modules\MosicoyStatusGrid\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
    Fields\CWidgetFieldSelect,
    Fields\CWidgetFieldIntegerBox
};

class WidgetForm extends CWidgetForm {

    public function addFields(): self {
        $this->addField(
            new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
        )->addField(
            new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
        );

        if ($this->isTemplateDashboard()) {
            $this->addField(
                new CWidgetFieldMultiSelectOverrideHost('override_hostid', _('Host'))
            );
        }

        $this->addField(
            (new CWidgetFieldSelect('sort_by', _('Sort by'), [
                0 => _('Worst severity first'),
                1 => _('Host name')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldSelect('cell_style', _('Cell style'), [
                0 => _('Compact'),
                1 => _('Comfortable'),
                2 => _('Detailed')
            ]))->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_hosts', _('Max hosts to display'), 1, 1000))
                ->setDefault(100)
        );

        return $this;
    }
}
