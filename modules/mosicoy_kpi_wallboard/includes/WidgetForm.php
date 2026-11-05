<?php declare(strict_types = 0);

namespace Modules\MosicoyKpiWallboard\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBox,
    Fields\CWidgetFieldIntegerBox,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
    Fields\CWidgetFieldPatternSelectItem,
    Fields\CWidgetFieldSelect,
    Fields\CWidgetFieldTextArea
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
            (new CWidgetFieldPatternSelectItem('items', _('Items')))
                ->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
        );

        $this->addField(
            (new CWidgetFieldTextArea('threshold_rules', _('Threshold rules (one per line: pattern:warn:crit[:dir])')))
                ->setDefault('')
        );

        $this->addField(
            (new CWidgetFieldSelect('cell_style', _('Card style'), [
                0 => _('Compact'),
                1 => _('Comfortable'),
                2 => _('Detailed')
            ]))->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_sparkline', _('Show sparkline (1h history)')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_delta', _('Show delta vs previous value')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_stats', _('Show min / avg / max stats')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('sort_by_severity', _('Sort cards by severity (critical first)')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_items', _('Max items'), 1, 24))
                ->setDefault(8)
        );

        return $this;
    }
}
