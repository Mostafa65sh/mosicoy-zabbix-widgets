<?php declare(strict_types = 0);

namespace Modules\MosicoySankeyFlow\Includes;

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
            new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
        );

        if ($this->isTemplateDashboard()) {
            $this->addField(
                new CWidgetFieldMultiSelectOverrideHost('override_hostid', _('Host'))
            );
        }

        $this->addField(
            (new CWidgetFieldSelect('color_theme', _('Color theme'), [
                0 => _('Mosicoy Gradient'),
                1 => _('Zabbix Native'),
                2 => _('Cool Blue')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_hosts', _('Max hosts to display'), 3, 100))
                ->setDefault(20)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_ok', _('Show OK hosts bucket')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_groups', _('Show groups (left column)')))
                ->setDefault(1)
        );

        return $this;
    }
}
