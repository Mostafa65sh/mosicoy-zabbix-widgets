<?php declare(strict_types = 0);

namespace Modules\MosicoyInterfaceErrors\Includes;

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
            new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
        );

        if ($this->isTemplateDashboard()) {
            $this->addField(
                new CWidgetFieldMultiSelectOverrideHost('override_hostid', _('Host'))
            );
        }

        $this->addField(
            (new CWidgetFieldTextBox('port_filter', _('Port filter')))
                ->setDefault('')
        );

        $this->addField(
            (new CWidgetFieldSelect('filter_mode', _('Filter mode'), [
                0 => _('All with issues'),
                1 => _('Critical only')
            ]))->setDefault(0)->setFlags(CWidgetField::FLAG_NOT_EMPTY)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('crit_threshold', _('Critical threshold'), 1, 100000))
                ->setDefault(10)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_clean', _('Show clean ports')))
                ->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_host', _('Show host name')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_rows', _('Max rows'), 5, 100))
                ->setDefault(30)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_hosts', _('Max switches'), 1, 50))
                ->setDefault(20)
        );

        return $this;
    }
}
