<?php declare(strict_types = 0);

namespace Modules\MosicoyNetworkMap\Includes;

use Zabbix\Widgets\{
    CWidgetField,
    CWidgetForm,
    Fields\CWidgetFieldCheckBox,
    Fields\CWidgetFieldIntegerBox,
    Fields\CWidgetFieldSelect,
    Fields\CWidgetFieldTextArea
};

class WidgetForm extends CWidgetForm {

    public function addFields(): self {
        $example =
            "# Format (one link per line):\n" .
            "#   source_host : target_host : label(optional)\n" .
            "#\n" .
            "# Example:\n" .
            "fw-01:sw-core-01:WAN\n" .
            "sw-core-01:sw-access-01:10G\n" .
            "sw-core-01:sw-access-02:10G\n" .
            "sw-access-01:srv-db-01:1G\n";

        $this->addField(
            (new CWidgetFieldTextArea('links', _('Links (source : target : label)')))
                ->setDefault($example)
        );

        $this->addField(
            (new CWidgetFieldSelect('layout', _('Layout'), [
                0 => _('Force directed'),
                1 => _('Circular'),
                2 => _('Hierarchical (top-down)')
            ]))->setDefault(0)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('show_link_labels', _('Show link labels')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldCheckBox('size_by_problems', _('Node size by problem count')))
                ->setDefault(1)
        );

        $this->addField(
            (new CWidgetFieldIntegerBox('max_nodes', _('Max nodes'), 1, 100))
                ->setDefault(50)
        );

        return $this;
    }
}
