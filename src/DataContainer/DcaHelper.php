<?php

namespace MadeYourDay\RockSolidCustomElements\DataContainer;

use Contao\Database;
use Contao\DataContainer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class DcaHelper
{
    private Request $request;

    public function __construct(RequestStack $requestStack)
    {
        $request = $requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \Exception('Missing request object');
        }

        $this->request = $request;
    }

    public function getDcaFieldValue(DataContainer $dc, string $fieldName, bool $fromDb = false)
    {
        if (!$fromDb && $this->request->request->get('FORM_SUBMIT') === $dc->table) {
            $value = $this->request->request->get($fieldName);
            if (null !== $value) {
                return $value;
            }
        }

        if ($dc->activeRecord) {
            return $dc->activeRecord->$fieldName;
        }

        $table = $dc->table;
        $id = $dc->id;

        $target = $this->request->get('target');
        if ($target) {
            $targetData = explode('.', $table, 3);
            if (is_array($targetData) && \count($targetData) >= 2) {
                $table = $targetData[0];
                $id = (int)$targetData[2];
            }
        }

        if ($table && $id) {
            $record = Database::getInstance()
                ->prepare("SELECT $fieldName FROM $table WHERE id = ?")
                ->execute($id)
            ;
            if ($record->next()) {
                return $record->$fieldName;
            }
        }

        return null;
    }
}
