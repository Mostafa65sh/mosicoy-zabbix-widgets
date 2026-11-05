<?php declare(strict_types = 0);

namespace Modules\MosicoyNetworkTopology\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBoxList,
    Fields\CWidgetFieldIntegerBox,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
    Fields\CWidgetFieldSelect
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
            (new CWidgetFieldSelect('layout', _('Layout'), [
                0 => _('Force directed'),
                1 => _('Circular'),
                2 => _('Grid')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBoxList('severity_filter', _('Show severities'), [
                0 => _('Not classified'),
                1 => _('Information'),
                2 => _('Warning'),
                3 => _('Average'),
                4 => _('High'),
                5 => _('Disaster')
            ]))->setDefault([2, 3, 4, 5])
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_hosts', _('Max hosts'), 3, 200))
                ->setDefault(50)
        );

        return $this;
    }
}
