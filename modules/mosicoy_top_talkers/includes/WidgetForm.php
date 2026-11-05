<?php declare(strict_types = 0);

namespace Modules\MosicoyTopTalkers\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBox,
    Fields\CWidgetFieldIntegerBox,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
    Fields\CWidgetFieldSelect,
    Fields\CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

    public function addFields(): self {
        $this->addField(
            new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
        )->addField(
            new CWidgetFieldMultiSelectHost('hostids', _('Hosts (switches / routers)'))
        );

        if ($this->isTemplateDashboard()) {
            $this->addField(
                new CWidgetFieldMultiSelectOverrideHost('override_hostid', _('Host'))
            );
        }

        $this->addField(
            (new CWidgetFieldTextBox('port_filter', _('Port filter (wildcard, empty = all)')))
                ->setDefault('')
        );

        $this->addField(
            new CWidgetFieldIntegerBox('top_n', _('Show top N ports'), 3, 50)
        );

        $this->addField(
            new CWidgetFieldSelect('metric', _('Sort metric'), [
                0 => _('Max (RX or TX)'),
                1 => _('RX + TX combined'),
                2 => _('RX only'),
                3 => _('TX only')
            ])
        );

        $this->addField(
            new CWidgetFieldSelect('display_unit', _('Display units'), [
                0 => _('Auto (Mbps / Gbps)'),
                1 => _('Mbps'),
                2 => _('Gbps')
            ])
        );

        $this->addField(
            new CWidgetFieldCheckBox('show_utilization', _('Show utilization % of link speed'))
        );

        $this->addField(
            new CWidgetFieldCheckBox('show_host', _('Show host name'))
        );

        $this->addField(
            new CWidgetFieldCheckBox('hide_down', _('Hide down interfaces'))
        );

        $this->addField(
            new CWidgetFieldIntegerBox('max_hosts', _('Max switches to scan'), 1, 50)
        );

        return $this;
    }
}
