<?php declare(strict_types = 0);

namespace Modules\MosicoyCalendar\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
    Fields\CWidgetFieldPatternSelectItem,
    Fields\CWidgetFieldSelect,
    Fields\CWidgetFieldTimePeriod
};

use CWidgetsData;

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
            (new CWidgetFieldTimePeriod('time_period', _('Time period')))
                ->setDefault([
                    CWidgetField::FOREIGN_REFERENCE_KEY => CWidgetField::createTypedReference(
                        CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_TIME_PERIOD
                    )
                ])
                ->setDefaultPeriod(['from' => 'now-30d', 'to' => 'now'])
                ->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
        );

        $this->addField(
            (new CWidgetFieldSelect('aggregate', _('Aggregate'), [
                0 => _('Sum'),
                1 => _('Average'),
                2 => _('Max'),
                3 => _('Min')
            ]))->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldSelect('color_palette', _('Color palette'), [
                0 => _('Cool Blue'),
                1 => _('Warm'),
                2 => _('GitHub Green'),
                3 => _('Emerald'),
                4 => _('Red')
            ]))->setDefault(0)
        );

        return $this;
    }
}
