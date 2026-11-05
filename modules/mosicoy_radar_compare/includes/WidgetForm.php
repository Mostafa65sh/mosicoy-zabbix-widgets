<?php declare(strict_types = 0);

namespace Modules\MosicoyRadarCompare\Includes;

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
            (new CWidgetFieldPatternSelectItem('items', _('Item patterns (one per axis)')))
                ->setFlags(CWidgetField::FLAG_LABEL_ASTERISK)
        );

        $this->addField(
            (new CWidgetFieldSelect('color_mode', _('Color mode'), [
                0 => _('Mosicoy palette'),
                1 => _('Cool blues'),
                2 => _('Warm sunset')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_legend', _('Show legend')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('fill_area', _('Fill area')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_series', _('Max hosts to compare'), 1, 20))
                ->setDefault(6)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_axis', _('Max indicators (axes)'), 3, 12))
                ->setDefault(8)
        );

        return $this;
    }
}
