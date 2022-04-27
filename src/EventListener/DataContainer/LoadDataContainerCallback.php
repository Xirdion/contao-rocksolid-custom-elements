<?php

namespace MadeYourDay\RockSolidCustomElements\EventListener\DataContainer;

use Contao\DataContainer;
use MadeYourDay\RockSolidCustomElements\CustomElement\Config;
use MadeYourDay\RockSolidCustomElements\DataContainer\DcaHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class LoadDataContainerCallback
{
    private Request $request;
    private DcaHelper $dcaHelper;
    private Config $config;

    public function __construct(RequestStack $requestStack, DcaHelper $dcaHelper, Config $config)
    {
        $request = $requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \Exception('Missing Request object in ' . __CLASS__);
        }

        $this->request = $request;
        $this->dcaHelper = $dcaHelper;
        $this->config = $config;
    }

    /**
     * tl_content, tl_module and tl_form_field DCA onload callback
     *
     * Reloads config and creates the DCA fields
     *
     * @param DataContainer|null $dc
     *
     * @return void
     */
    public function __invoke(?DataContainer $dc): void
    {
        if (null === $dc) {
            return;
        }

        $act = $this->request->query->get('act');
        if ('create' === $act) {
            return;
        }

        if ('edit' === $act) {
            $this->config->reloadConfig();
        }

        if ($dc->table === 'tl_content' && class_exists('CeAccess')) {
            $ceAccess = new CeAccess;
            $ceAccess->filterContentElements($dc);
        }

        if ('editAll' === $act) {
            return $this->createDcaMultiEdit($dc);
        }

        $type = $this->dcaHelper->getDcaFieldValue($dc, 'type');
        if (!$type || str_starts_with($type, 'rsce_')) {
            return;
        }

        $data = $this->dcaHelper->getDcaFieldValue($dc, 'rsce_data', true);
        if ($data && str_starts_with($data, '{')) {
            try {
                $this->data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->data = null;
            }
        }

        // Check if a dca form was submitted
        $createFromPost = $this->request->request->get('FORM_SUBMIT') === $dc->table;
        $treeField = $this->getTreeField($dc);

        $this->createDca($dc, $type, $createFromPost, $treeField);
    }

    /**
     * Ensures that the fileTree oder pageTree field exists.
     *
     * @param DataContainer $dc
     *
     * @return string|null
     */
    private function getTreeField(DataContainer $dc): ?string
    {
        $field = $this->request->query->get('field');
        if ($field && str_starts_with($field, 'rsce_field_')) {
            return $field;
        }

        $target = $this->request->query->get('target');
        if ($target) {
            $targetData = explode('.', $target, 3);
            if (\is_array($targetData) && \count($targetData) >= 2 && $targetData[0] === $dc->table && str_starts_with($targetData[1], 'rsce_field_')) {
                return $target[1];
            }
        }

        $name = $this->request->request->get('name');

        if ($name && str_starts_with($name, 'rsce_field_')) {
            return $name;
        }

        return null;
    }
}