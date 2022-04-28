<?php

namespace MadeYourDay\RockSolidCustomElements\EventListener\DataContainer;

use Contao\DataContainer as ContaoDca;
use MadeYourDay\RockSolidCustomElements\CustomElement\Config;
use MadeYourDay\RockSolidCustomElements\CustomElement\DataContainer;
use Symfony\Component\HttpFoundation\RequestStack;

class LoadDataContainerCallback
{
    private RequestStack $requestStack;
    private DataContainer $dcaHelper;
    private Config $config;

    public function __construct(RequestStack $requestStack, DataContainer $dcaHelper, Config $config)
    {
        $this->requestStack = $requestStack;
        $this->dcaHelper = $dcaHelper;
        $this->config = $config;
    }

    /**
     * tl_content, tl_module and tl_form_field DCA onload callback
     *
     * Reloads config and creates the DCA fields
     *
     * @param ContaoDca|null $dc
     *
     * @return void
     */
    public function __invoke(?ContaoDca $dc): void
    {
        if (null === $dc) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        $act = $request->query->get('act');
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
            $this->dcaHelper->createDcaMultiEdit($dc);

            return;
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
        $createFromPost = $request->request->get('FORM_SUBMIT') === $dc->table;
        $treeField = $this->getTreeField($dc);

        $this->dcaHelper->createDca($dc, $type, $createFromPost, $treeField);
    }

    /**
     * Ensures that the fileTree oder pageTree field exists.
     *
     * @param ContaoDca $dc
     *
     * @return string|null
     */
    private function getTreeField(ContaoDca $dc): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        $field = $request->query->get('field');
        if ($field && str_starts_with($field, 'rsce_field_')) {
            return $field;
        }

        $target = $request->query->get('target');
        if ($target) {
            $targetData = explode('.', $target, 3);
            if (\is_array($targetData) && \count($targetData) >= 2 && $targetData[0] === $dc->table && str_starts_with($targetData[1], 'rsce_field_')) {
                return $target[1];
            }
        }

        $name = $request->request->get('name');

        if ($name && str_starts_with($name, 'rsce_field_')) {
            return $name;
        }

        return null;
    }
}
