<?php declare(strict_types = 0);

namespace Modules\MosicoyHeatmapMatrix\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBox,
    Fields\CWidgetFieldIntegerBox,
    Fields\CWidgetFieldMultiSelectGroup,
    Fields\CWidgetFieldMultiSelectHost,
    Fields\CWidgetFieldMultiSelectOverrideHost,
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

        $default_columns =
            "# Format: pattern : name : warn : crit : dir(up|down)\n" .
            "# dir auto = free/avail/idle → down. Add as many lines as you want.\n" .
            "\n" .
            "CPU utilization:CPU:70:90\n" .
            "Memory utilization:RAM:80:95\n" .
            "Space utilization:Disk:75:90\n" .
            "Available memory:Free:20:10:down\n" .
            "\n" .
            "# Network example (bps):\n" .
            "# Interface eth0: Bits received:Net In:8000000:9500000\n" .
            "# Interface eth0: Bits sent:Net Out:8000000:9500000\n" .
            "\n" .
            "# Sessions example:\n" .
            "# Number of logged in users:Sessions:50:100\n";

        $this->addField(
            (new CWidgetFieldTextArea('columns', _('Columns (one per line: pattern:name:warn:crit[:dir])')))
                ->setDefault($default_columns)
        );

        $this->addField(
            (new CWidgetFieldSelect('color_mode', _('Color mode'), [
                0 => _('Auto (green → red)'),
                1 => _('Traffic light'),
                2 => _('Cool blue')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldSelect('sort_by', _('Sort hosts by'), [
                0 => _('Worst severity first'),
                1 => _('Host name'),
                2 => _('Number of critical cells')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_values', _('Show values in cells')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('compact', _('Compact mode')))
                ->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_hosts', _('Max hosts'), 1, 200))
                ->setDefault(50)
        );

        return $this;
    }
}
