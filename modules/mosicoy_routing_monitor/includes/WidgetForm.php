<?php declare(strict_types = 0);

namespace Modules\MosicoyRoutingMonitor\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBox,
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
            new CWidgetFieldMultiSelectHost('hostids', _('Hosts (routers / L3 switches)'))
        );

        if ($this->isTemplateDashboard()) {
            $this->addField(
                new CWidgetFieldMultiSelectOverrideHost('override_hostid', _('Host'))
            );
        }

        $this->addField(
            new CWidgetFieldSelect('proto_filter', _('Protocol'), [
                0 => _('Both BGP & OSPF'),
                1 => _('BGP only'),
                2 => _('OSPF only')
            ])
        );

        $this->addField(
            new CWidgetFieldCheckBox('hide_established', _('Hide healthy peers (show only problems)'))
        );

        $this->addField(
            new CWidgetFieldCheckBox('show_host', _('Show host name'))
        );

        $this->addField(
            new CWidgetFieldIntegerBox('max_peers', _('Max peers to show'), 5, 200)
        );

        $this->addField(
            new CWidgetFieldIntegerBox('max_hosts', _('Max routers to scan'), 1, 50)
        );

        return $this;
    }
}
