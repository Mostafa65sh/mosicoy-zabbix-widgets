<?php declare(strict_types = 0);

namespace Modules\MosicoySwitchPanel\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBox,
    Fields\CWidgetFieldIntegerBox,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
    Fields\CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

    public function addFields(): self {
        $this->addField(
            new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
        )->addField(
            new CWidgetFieldMultiSelectHost('hostids', _('Hosts (switches)'))
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
            (new CWidgetFieldIntegerBox('warn_threshold', _('Warning threshold %'), 1, 100))
                ->setDefault(60)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('crit_threshold', _('Critical threshold %'), 1, 100))
                ->setDefault(80)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_down', _('Show down ports')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_management', _('Show management ports (Vlan, Loopback...)')))
                ->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_sparklines', _('Show traffic sparklines (last 1h)')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('compact', _('Compact mode')))
                ->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_hosts', _('Max switches'), 1, 20))
                ->setDefault(5)
        );

        return $this;
    }
}
