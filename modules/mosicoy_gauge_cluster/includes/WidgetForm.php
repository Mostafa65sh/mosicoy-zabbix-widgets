<?php declare(strict_types = 0);

namespace Modules\MosicoyGaugeCluster\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBox,
    Fields\CWidgetFieldIntegerBox,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
    Fields\CWidgetFieldPatternSelectItem,
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
            (new CWidgetFieldPatternSelectItem('items', _('Items')))
                ->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
        );

        $this->addField(
            (new CWidgetFieldSelect('cell_style', _('Cell style'), [
                0 => _('Compact'),
                1 => _('Comfortable'),
                2 => _('Detailed')
            ]))->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldSelect('color_mode', _('Color mode'), [
                0 => _('Auto (green → red)'),
                1 => _('Zabbix severity'),
                2 => _('Cool (blue tones)'),
                3 => _('Monochrome')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('fixed_max', _('Fixed max (0 = auto)'), 0, 100000000))
                ->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_delta', _('Show delta vs previous value')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_items', _('Max items to display'), 1, 100))
                ->setDefault(16)
        );

        return $this;
    }
}
