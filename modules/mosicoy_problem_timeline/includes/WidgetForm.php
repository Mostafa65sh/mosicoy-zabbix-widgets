<?php declare(strict_types = 0);

namespace Modules\MosicoyProblemTimeline\Includes;

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
            (new CWidgetFieldSelect('time_window', _('Time window'), [
                0 => _('Last 1 hour'),
                1 => _('Last 6 hours'),
                2 => _('Last 24 hours'),
                3 => _('Last 7 days')
            ]))->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldSelect('sort_by', _('Sort by'), [
                0 => _('Severity (highest first)'),
                1 => _('Duration (longest first)'),
                2 => _('Host name')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_acknowledged', _('Show acknowledged problems')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_problems', _('Max problems to show'), 1, 200))
                ->setDefault(50)
        );

        return $this;
    }
}
