<?php

namespace MadeYourDay\RockSolidCustomElements\EventListener\DataContainer;

use Contao\DataContainer as ContaoDca;
use MadeYourDay\RockSolidCustomElements\CustomElement\DataContainer;

class SubmitDataContainerCallback
{
    private DataContainer $dcaHelper;

    public function __construct(DataContainer $dcaHelper)
    {
        $this->dcaHelper = $dcaHelper;
    }

    /**
     * tl_content, tl_module and tl_form_field DCA onsubmit callback
     *
     * Creates empty arrays for empty lists if no data is available
     * (e.g. for new elements)
     *
     * @param ContaoDca $dc
     *
     * @return void
     */
    public function __invoke(ContaoDca $dc): void
    {
        $type = $this->dcaHelper->getDcaFieldValue($dc, 'type');
        if (!$type || substr($type, 0, 5) !== 'rsce_') {
            return;
        }

        $data = $this->dcaHelper->getDcaFieldValue($dc, 'rsce_data', true);

        // Check if it is a new element with no data
        if ($data === null && !count($this->saveData)) {

            // Creates empty arrays for empty lists, see #4
            $data = $this->saveDataCallback(null, $dc);

            if ($data && substr($data, 0, 1) === '{') {
                \Database::getInstance()
                    ->prepare("UPDATE {$dc->table} SET rsce_data = ? WHERE id = ?")
                    ->execute($data, $dc->id);
            }

        }
    }
}