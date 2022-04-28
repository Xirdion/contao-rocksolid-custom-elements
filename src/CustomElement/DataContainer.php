<?php

namespace MadeYourDay\RockSolidCustomElements\CustomElement;

use Contao\BackendUser;
use Contao\Database;
use Contao\DataContainer as ContaoDca;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class DataContainer
{
    private RequestStack $requestStack;
    private SessionInterface $session;
    private Config $config;

    public function __construct(RequestStack $requestStack, SessionInterface $session, Config $config)
    {
        $this->requestStack = $requestStack;
        $this->session = $session;
        $this->config = $config;
    }

    /**
     * @param ContaoDca $dc
     * @param string $fieldName
     * @param bool $fromDb
     *
     * @return mixed|null
     */
    public function getDcaFieldValue(ContaoDca $dc, string $fieldName, bool $fromDb = false)
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return null;
        }

        if (!$fromDb && $request->request->get('FORM_SUBMIT') === $dc->table) {
            $value = $request->request->get($fieldName);
            if (null !== $value) {
                return $value;
            }
        }

        if ($dc->activeRecord) {
            return $dc->activeRecord->$fieldName;
        }

        $table = $dc->table;
        $id = $dc->id;

        $target = $request->get('target');
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
                ->execute($id);
            if ($record->next()) {
                return $record->$fieldName;
            }
        }

        return null;
    }

    /**
     * Create all DCA fields for the specified type
     *
     * @param ContaoDca $dc Data container
     * @param string $type The template name
     * @param boolean $createFromPost Whether to create the field structure from post data or not
     * @param string $tmpField Field name to create temporarily for page or file tree widget ajax calls
     * @return void
     */
    public function createDca($dc, $type, $createFromPost = false, $tmpField = null)
    {
        $config = $this->config->getConfigByType($type);

        if (!$config) {
            return;
        }

        $assetsDir = 'bundles/rocksolidcustomelements';

        if (TL_MODE === 'BE') {
            $GLOBALS['TL_JAVASCRIPT'][] = $assetsDir . '/js/be_main.js';
            $GLOBALS['TL_CSS'][] = $assetsDir . '/css/be_main.css';
        }

        $paletteFields = array();
        $standardFields = is_array($config['standardFields'] ?? null) ? $config['standardFields'] : array();
        $this->fieldsConfig = $config['fields'];

        foreach ($this->fieldsConfig as $fieldName => $fieldConfig) {
            $this->createDcaItem('rsce_field_', $fieldName, $fieldConfig, $paletteFields, $dc, $createFromPost);
        }
        if ($tmpField && !in_array($tmpField, $paletteFields)) {
            $fieldConfig = $this->config->getNestedConfig($tmpField, $this->fieldsConfig);
            if ($fieldConfig) {
                $this->createDcaItem($tmpField, '', $fieldConfig, $paletteFields, $dc, false);
            }
        }

        $GLOBALS['TL_DCA'][$dc->table]['fields']['rsce_data']['eval']['rsceScript'] = 'window.rsceInit([...document.querySelectorAll("script")].pop().parentNode.parentNode.parentNode);';

        $paletteFields[] = 'rsce_data';

        $GLOBALS['TL_DCA'][$dc->table]['palettes'][$type] = static::generatePalette(
            $dc->table,
            $paletteFields,
            $standardFields
        );

        $GLOBALS['TL_DCA'][$dc->table]['fields']['customTpl']['options_callback'] = function ($dc) {
            $templates = \Controller::getTemplateGroup($dc->activeRecord->type . '_', [], $dc->activeRecord->type);
            foreach ($templates as $key => $label) {
                if (substr($key, -7) === '_config' || $key === $dc->activeRecord->type) {
                    unset($templates[$key]);
                }
            }
            return $templates;
        };

        $GLOBALS['TL_LANG'][$dc->table]['rsce_legend'] = $GLOBALS['TL_LANG'][$dc->table === 'tl_content' ? 'CTE' : ($dc->table === 'tl_module' ? 'FMD' : 'FFL')][$type][0];

        if (!empty($config['onloadCallback']) && is_array($config['onloadCallback'])) {
            foreach ($config['onloadCallback'] as $callback) {
                if (is_array($callback)) {
                    \System::importStatic($callback[0])->{$callback[1]}($dc);
                } else if (is_callable($callback)) {
                    $callback($dc);
                }
            }
        }
    }

    public function createDcaMultiEdit(ContaoDca $dc): void
    {
        $session = $this->session->all();
        if (empty($session['CURRENT']['IDS']) || !is_array($session['CURRENT']['IDS'])) {
            return;
        }

        $ids = (array)$session['CURRENT']['IDS'];

        $sql = 'SELECT type FROM %s WHERE id IN (%s) AND type LIKE "rsce_%%" GROUP BY type';
        $sql = sprintf($sql, $dc->table, implode(',', $ids));
        $types = Database::getInstance()
            ->prepare($sql)
            ->execute()
            ->fetchEach('type');

        if (!$types) {
            return;
        }

        foreach ($types as $type) {
            $paletteFields = [];
            $config = $this->config->getConfigByType($type);

            if (!$config) {
                continue;
            }

            $standardFields = is_array($config['standardFields'] ?? null) ? $config['standardFields'] : array();

            foreach ($config['fields'] as $fieldName => $fieldConfig) {
                if (isset($fieldConfig['inputType']) && $fieldConfig['inputType'] !== 'list') {
                    $this->createDcaItem($type . '_field_', $fieldName, $fieldConfig, $paletteFields, $dc, false, true);
                }
            }

            $GLOBALS['TL_DCA'][$dc->table]['palettes'][$type] = static::generatePalette(
                $dc->table,
                $paletteFields,
                $standardFields
            );
        }
    }

    /**
     * @param string $fieldPrefix
     * @param string $fieldName
     * @param string|array $fieldConfig
     * @param array $paletteFields
     * @param ContaoDca $dc
     * @param bool $createFromPost
     * @param bool $multiEdit
     *
     * @return void
     *
     * @throws \Exception
     */
    public function createDcaItem(string $fieldPrefix, $fieldName, $fieldConfig, array &$paletteFields, ContaoDca $dc, bool $createFromPost, bool $multiEdit = false): void
    {
        if (!is_string($fieldConfig) && !is_array($fieldConfig)) {
            throw new \Exception('Field config must be of type array or string.');
        }

        // Do some checks on the field name
        if (false !== strpos($fieldName, '__')) {
            throw new \Exception('Field name must not include "__" (' . $this->getDcaFieldValue($dc, 'type') . ': ' . $fieldName . ').');
        }

        if (false !== strpos($fieldName, 'rsce_field_')) {
            throw new \Exception('Field name must not include "rsce_field_" (' . $this->getDcaFieldValue($dc, 'type') . ': ' . $fieldName . ').');
        }

        if (str_starts_with($fieldName, '') || str_ends_with($fieldName, '_')) {
            throw new \Exception('Field name must not start or end with "_" (' . $this->getDcaFieldValue($dc, 'type') . ': ' . $fieldName . ').');
        }

        if (!is_string($fieldName)) {
            $fieldName = 'unnamed_' . $fieldName;
        }

        if (is_string($fieldConfig)) {
            $fieldConfig = [
                'inputType' => 'group',
                'label' => [$fieldConfig, ''],
            ];
        }

        // Check access rights
        $hasAccess = BackendUser::getInstance()->hasAccess($dc->table . '::rsce_data', 'alexf');
        if (false === $hasAccess && 'standardField' !== $fieldConfig['inputType']) {
            return;
        }

        // Translate label value
        if (isset($fieldConfig['label'])) {
            $label = Translator::translateLabel($fieldConfig['label']);

            // Do not overwrite referenced variable
            unset($fieldConfig['label']);
            $fieldConfig['label'] = $label;
        }

        if (
            isset($fieldConfig['reference'])
            && is_array($fieldConfig['reference'])
            && count(array_filter($fieldConfig['reference'], 'is_array'))
        ) {
            $reference = array_map(static fn($label) => Translator::translateLabel($label), $fieldConfig['reference']);

            // Don’t overwrite referenced variable
            unset($fieldConfig['reference']);
            $fieldConfig['reference'] = $reference;
        }

        if (isset($fieldConfig['dependsOn'])) {
            // Convert dependsOn config to array
            if (\is_string($fieldConfig['dependsOn'])) {
                $fieldConfig['dependsOn'] = ['field' => $fieldConfig['dependsOn']];
            }

            if (\is_array($fieldConfig['dependsOn'])) {
                $tlClass = $fieldConfig['eval']['tl_class'] ?? '';
                $tlClass .= ' rsce-depends-on-' . rawurlencode(
                        json_encode([
                            'field' => $this->getDependingFieldName($fieldConfig['dependsOn'], $fieldPrefix),
                            'value' => $fieldConfig['dependsOn']['value'] ?? true,
                        ])
                    );

                $fieldConfig['eval']['tl_class'] = $tlClass;
            }
        }

        if ('list' === $fieldConfig['inputType']) {
            if (isset($fieldConfig['elementLabel'])) {
                $label = Translator::translateLabel($fieldConfig['elementLabel']);

                // Don’t overwrite referenced variable
                unset($fieldConfig['elementLabel']);
                $fieldConfig['elementLabel'] = $label;
            } else {
                $fieldConfig['elementLabel'] = "%s";
            }

            $fieldConfig['minItems'] = (int)($fieldConfig['minItems'] ?? 0);
            $fieldConfig['maxItems'] = isset($fieldConfig['maxItems']) ? (int)$fieldConfig['maxItems'] : null;

            if ($fieldConfig['maxItems'] && $fieldConfig['maxItems'] < $fieldConfig['minItems']) {
                throw new \Exception('maxItems must not be higher than minItems (' . $this->getDcaFieldValue($dc, 'type') . ': ' . $fieldName . ').');
            }

            $GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldPrefix . $fieldName . '_rsce_list_start'] = [
                'label' => $fieldConfig['label'],
                'inputType' => 'rsce_list_start',
                'eval' => array_merge($fieldConfig['eval'] ?? [], [
                    'minItems' => $fieldConfig['minItems'],
                    'maxItems' => $fieldConfig['maxItems'],
                ]),
            ];
            $paletteFields[] = $fieldPrefix . $fieldName . '_rsce_list_start';

            $hasFields = false;
            foreach ($fieldConfig['fields'] as $fieldConfig2) {
                if (isset($fieldConfig2['inputType']) && $fieldConfig2['inputType'] !== 'list') {
                    $hasFields = true;
                }
            }
            if (!$hasFields) {
                // add an empty field
                $fieldConfig['fields']['rsce_empty'] = [
                    'inputType' => 'text',
                    'eval' => ['tl_class' => 'hidden'],
                ];
            }

            $this->createDcaItemListDummy($fieldPrefix, $fieldName, $fieldConfig, $paletteFields, $dc, $createFromPost);

            $fieldData = $this->getNestedValue($fieldPrefix . $fieldName);

            for (
                $dataKey = 0;
                $dataKey < $fieldConfig['minItems'] || ($createFromPost ? $this->wasListFieldSubmitted($fieldPrefix . $fieldName, $dataKey) : isset($fieldData[$dataKey]));
                $dataKey++
            ) {

                if (is_int($fieldConfig['maxItems']) && $dataKey > $fieldConfig['maxItems'] - 1) {
                    break;
                }

                $GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_start'] = array(
                    'inputType' => 'rsce_list_item_start',
                    'label' => array(sprintf($fieldConfig['elementLabel'], $dataKey + 1)),
                    'eval' => array(
                        'label_template' => $fieldConfig['elementLabel'],
                    ),
                );
                $paletteFields[] = $fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_start';

                foreach ($fieldConfig['fields'] as $fieldName2 => $fieldConfig2) {
                    $this->createDcaItem($fieldPrefix . $fieldName . '__' . $dataKey . '__', $fieldName2, $fieldConfig2, $paletteFields, $dc, $createFromPost);
                }

                $GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_stop'] = array(
                    'inputType' => 'rsce_list_item_stop',
                );
                $paletteFields[] = $fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_stop';

            }

            $GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldPrefix . $fieldName . '_rsce_list_stop'] = array(
                'inputType' => 'rsce_list_stop',
            );
            $paletteFields[] = $fieldPrefix . $fieldName . '_rsce_list_stop';

        }
    }

    /**
     * Get depending field name from dependsOn config resolving relative ../ parts
     *
     * @param array $config
     * @param string $prefix
     * @return string
     */
    private function getDependingFieldName(array $config, string $prefix): string
    {
        if (empty($config['field'])) {
            return '';
        }

        $field = $config['field'];
        $prefixParts = explode('__', substr($prefix, 11));

        if ('/' === $field[0]) {
            return substr($field, 1);
        }

        while (0 === strpos($field, '../')) {
            $field = substr($field, 3);

            if (!\count($prefixParts)) {
                throw new \RuntimeException(sprintf('Invalid field path "%s" for prefix "%s".', $config['field'], $prefix));
            }

            array_splice($prefixParts, -2);
        }

        if (!\count($prefixParts)) {
            return $field;
        }

        $prefixParts[\count($prefixParts) - 1] = "";

        return 'rsce_field_' . implode('__', $prefixParts) . $field;
    }

    /**
     * Create one list item dummy with the specified parameters
     *
     * @param string $fieldPrefix Field prefix, e.g. "rsce_field_"
     * @param string $fieldName Field name
     * @param array $fieldConfig Field configuration array
     * @param array $paletteFields Reference to the list of all fields
     * @param ContaoDca $dc Data container
     * @param bool $createFromPost Whether to create the field structure from post data or not
     *
     * @return void
     *
     * @throws \Exception
     */
    private function createDcaItemListDummy(string $fieldPrefix, string $fieldName, array $fieldConfig, array &$paletteFields, ContaoDca $dc, bool $createFromPost): void
    {
        $dataKey = 'rsce_dummy';

        $GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_start'] = array(
            'inputType' => 'rsce_list_item_start',
            'label' => array($fieldConfig['elementLabel']),
            'eval' => array(
                'tl_class' => 'rsce_list_item_dummy',
                'label_template' => $fieldConfig['elementLabel'],
            ),
        );
        $paletteFields[] = $fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_start';

        foreach ($fieldConfig['fields'] as $fieldName2 => $fieldConfig2) {
            $this->createDcaItem($fieldPrefix . $fieldName . '__' . $dataKey . '__', $fieldName2, $fieldConfig2, $paletteFields, $dc, $createFromPost);
        }

        $GLOBALS['TL_DCA'][$dc->table]['fields'][$fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_stop'] = array(
            'inputType' => 'rsce_list_item_stop',
        );
        $paletteFields[] = $fieldPrefix . $fieldName . '__' . $dataKey . '_rsce_list_item_stop';
    }

    /**
     * Get the value of the nested data array $this->data from field name
     *
     * @param string $field Field name
     * @param bool $fromSaveData True to retrieve the value from $this->saveData instead of $this->data
     * @return mixed         Value from $this->data or $this->saveData
     */
    private function getNestedValue(string $field, bool $fromSaveData = false)
    {
        $field = preg_split('(__([0-9]+)__)', substr($field, 11), -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($fromSaveData) {
            if (!isset($this->saveData[$field[0]])) {
                return null;
            }
            $data =& $this->saveData[$field[0]];
        } else {
            if (!isset($this->data[$field[0]])) {
                return null;
            }
            $data =& $this->data[$field[0]];
        }

        for ($i = 0; isset($field[$i]); $i += 2) {

            if (isset($field[$i + 1])) {
                if (!isset($data[$field[$i + 1]])) {
                    return null;
                }
                if (!isset($data[$field[$i + 1]][$field[$i + 2]])) {
                    return null;
                }
                $data =& $data[$field[$i + 1]][$field[$i + 2]];
            } else {
                return $data;
            }
        }

        return null;
    }

    /**
     * Check if a field was submitted via POST
     *
     * @param string $fieldName field name to check
     * @param int $dataKey data index
     *
     * @return bool true if the field was submitted via POST
     */
    private function wasListFieldSubmitted(string $fieldName, int $dataKey): bool
    {
        if (!is_array(\Input::post('FORM_FIELDS'))) {
            return false;
        }

        if (strpos($fieldName, '__rsce_dummy__') !== false) {
            return false;
        }

        $formFields = array_unique(\StringUtil::trimsplit(
            '[,;]',
            implode(',', \Input::post('FORM_FIELDS'))
        ));

        $fieldPrefix = $fieldName . '__' . $dataKey . '__';

        foreach ($formFields as $field) {
            if (0 === strpos($field, $fieldPrefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generates the palette definition
     *
     * @param string $table "tl_content", "tl_module" or "tl_form_field"
     * @param array $paletteFields Palette fields
     * @param array $standardFields Standard fields
     * @return string                 Palette definition
     */
    private static function generatePalette(string $table, array $paletteFields = [], array $standardFields = []): string
    {
        $palette = '';

        if ($table === 'tl_module') {
            $palette .= '{title_legend},name';
            if (in_array('headline', $standardFields)) {
                $palette .= ',headline';
            }
            $palette .= ',type';
        } else {
            $palette .= '{type_legend},type';
            if ($table === 'tl_content' && in_array('headline', $standardFields)) {
                $palette .= ',headline';
            }
            if (in_array('columns', $standardFields)) {
                $palette .= ';{rs_columns_legend},rs_columns_large,rs_columns_medium,rs_columns_small';
            }
            if (in_array('text', $standardFields)) {
                $palette .= ';{text_legend},text';
            }
        }

        if (
            isset($paletteFields[0])
            && $paletteFields[0] !== 'rsce_data'
            && isset($GLOBALS['TL_DCA'][$table]['fields'][$paletteFields[0]]['inputType'])
            && $GLOBALS['TL_DCA'][$table]['fields'][$paletteFields[0]]['inputType'] !== 'rsce_group_start'
            && $GLOBALS['TL_DCA'][$table]['fields'][$paletteFields[0]]['inputType'] !== 'rsce_list_start'
        ) {
            $palette .= ';{rsce_legend}';
        }

        $palette .= ',' . implode(',', $paletteFields);

        if ($table === 'tl_content' && in_array('image', $standardFields)) {
            $palette .= ';{image_legend},addImage';
        }

        if ($table === 'tl_form_field') {
            $palette .= ';{expert_legend:hide},class';
        }

        $palette .= ';{template_legend:hide},customTpl';

        if ($table !== 'tl_form_field') {

            $palette .= ';{protected_legend:hide},protected;{expert_legend:hide},guests';

            if (in_array('cssID', $standardFields)) {
                $palette .= ',cssID';
            }

        }

        if ($table === 'tl_content') {
            $palette .= ';{invisible_legend:hide},invisible,start,stop';
        }

        return $palette;
    }
}