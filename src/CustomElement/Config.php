<?php

namespace MadeYourDay\RockSolidCustomElements\CustomElement;

use Contao\Controller;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Database;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Exception;
use MadeYourDay\RockSolidCustomElements\Template\CustomTemplate;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;

class Config
{
    private RequestStack $requestStack;
    private string $projectDir;
    private string $cacheDir;
    private LoggerInterface $logger;
    private bool $saveToCache = true;

    public function __construct(RequestStack $requestStack, ContainerInterface $container, LoggerInterface $logger)
    {
        $this->requestStack = $requestStack;
        $this->projectDir = $container->getParameter('kernel.project_dir');
        $this->cacheDir = $container->getParameter('kernel.cache_dir') . '/contao';
        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public function getCacheFilePaths(): array
    {
        $filePath = $this->cacheDir . '/rocksolid_custom_elements_config.php';
        return [
            'path' => StringUtil::stripRootDir($filePath),
            'fullPath' => $filePath,
        ];
    }

    public function loadConfig(bool $bypassCache = false): void
    {
        $filePaths = $this->getCacheFilePaths();

        $rsceGlob = glob($this->projectDir . '/templates/rsce_*') ?: [];
        $rsceCustomGlob = glob($this->projectDir . '/templates/*/rsce_*') ?: [];

        $cacheHash = md5(implode(',', array_merge($rsceGlob, $rsceCustomGlob)));

        if (!$bypassCache && file_exists($filePaths['fullPath'])) {
            $fileCacheHash = null;
            include $filePaths['fullPath'];
            if ($fileCacheHash === $cacheHash) {
                // The cache file is valid and loaded
                return;
            }
        }

        System::loadLanguageFile('default');
        System::loadLanguageFile('tl_content');
        System::loadLanguageFile('tl_module');

        ['tempaltes' => $templates, 'fallback' => $fallbackConfigPaths] = $this->getTemplatesData();
        $themeNamesByTemplateDir = $this->getThemeData();

        // Create the elements
        $elements = $this->createElements($templates, $fallbackConfigPaths, $themeNamesByTemplateDir);

        // Sort the elements
        usort($elements, static function($a, $b) {
            if ($a['path'] !== $b['path']) {
                if ($a['path'] === 'templates') {
                    return -1;
                }
                if ($b['path'] === 'templates') {
                    return 1;
                }
                return strcmp($a['labelPrefix'], $b['labelPrefix']);
            }
            return strcmp($a['template'], $b['template']);
        });

        $contents = $this->createContent($elements, $cacheHash);

        if (!$this->saveToCache) {
            return;
        }

        (new Filesystem())->dumpFile($filePaths['fullPath'], implode(PHP_EOL, $contents));
        Cache::refreshOpcodeCache($filePaths['fullPath']);
    }

    public function reloadConfig(): void
    {
        $this->loadConfig(true);
    }

    public function getConfigByType(string $type): ?array
    {
        $configPath = null;

        try {
            $templatePaths = CustomTemplate::getTemplates($type);
            if (!empty($templatePaths[0])) {
                $configPath = substr($templatePaths[0], 0, -6) . '_config.php';
            }
        } catch (\Exception $e) {
            $configPath = null;
        }

        if (null === $configPath || !file_exists($configPath)) {
            $allConfigs = array_merge(
                glob($this->projectDir . '/templates/' . $type . '_config.php') ?: [],
                glob($this->projectDir . '/templates/*/' . $type . '_config.php') ?: []
            );

            if (count($allConfigs)) {
                $configPath = $allConfigs[0];
            } else {
                return null;
            }
        }

        $config = include $configPath;

        if ($config) {
            $config['fields'] = is_array($config['fields'] ?? null) ? $config['fields'] : array();
        }

        return $config;
    }

    /**
     * Get the config from field name
     *
     * @param  string $field Field name
     * @return mixed         Configuration of the field
     */
    public function getNestedConfig(string $field, $config)
    {
        $field = preg_split('(__([0-9]+)__)', substr($field, 11), -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!isset($config[$field[0]])) {
            return null;
        }

        $fieldConfig =& $config[$field[0]];

        for ($i = 0; isset($field[$i]); $i += 2) {

            if (isset($field[$i + 1])) {
                if (!isset($fieldConfig['fields'])) {
                    return null;
                }
                if (!isset($fieldConfig['fields'][$field[$i + 2]])) {
                    return null;
                }
                $fieldConfig =& $fieldConfig['fields'][$field[$i + 2]];
            }
            else {
                return $fieldConfig;
            }
        }

        return null;
    }

    private function getAllConfigs(): array
    {
        $rsceGlob = glob($this->projectDir . '/templates/rsce_*_config.php') ?: [];
        $rsceCustomGlob = glob($this->projectDir . '/templates/*/rsce_*_config.php') ?: [];
        $allConfigs = array_merge($rsceGlob, $rsceCustomGlob);

        $duplicateConfigs = array_filter(
            array_count_values(
                array_map(
                    static fn ($configPath) => basename($configPath, '_config.php'),
                    $allConfigs
                )
            ),
            static fn ($count) => $count > 1
        );

        if (\count($duplicateConfigs)) {
            $this->logger->log(
                LogLevel::ERROR,
                'Duplicate Custom Elements found: ' . implode(', ', array_keys($duplicateConfigs)),
                ['contao' => new ContaoContext(__METHOD__, 'Error')]
            );
        }

        return $allConfigs;
    }

    private function getTemplatesData(): array
    {
        $allConfigs = $this->getAllConfigs();
        $templates = Controller::getTemplateGroup('rsce_');
        $fallbackConfigPaths = [];

        foreach ($allConfigs as $configPath) {
            $templateName = basename($configPath, '_config.php');
            if (
                file_exists(substr($configPath, 0, -11) . '.html5')
                || file_exists(substr($configPath, 0, -11) . '.html.twig')
            ) {
                if (!isset($templates[$templateName])) {
                    $templates[$templateName] = $templateName;
                }
                if (!isset($fallbackConfigPaths[$templateName])) {
                    $fallbackConfigPaths[$templateName] = $configPath;
                }
            }
        }

        return [
            'templates' => $templates,
            'fallback' => $fallbackConfigPaths,
        ];
    }

    private function getThemeData(): array
    {
        try {
            $themes = Database::getInstance()
                ->prepare('SELECT name, templates FROM tl_theme')
                ->execute()
                ->fetchAllAssoc()
            ;
        }
        catch (DBALException $e) {
            $themes = [];
        }
        catch (Exception $e) {
            $themes = [];
        }

        $themeNamesByTemplateDir = [];
        foreach ($themes as $theme) {
            if ($theme['templates']) {
                $themeNamesByTemplateDir[$theme['templates']] = $theme['name'];
            }
        }

        return $themeNamesByTemplateDir;
    }

    private function createElements(array $templates, array $fallbackConfigPaths, array $themeNamesByTemplateDir): array
    {
        $this->saveToCache = true;
        $elements = [];

        foreach ($templates as $template => $label) {
            if ('_config' === substr($template, -7)) {
                continue;
            }

            $configPath = null;
            try {
                $templatePaths = CustomTemplate::getTemplates($template);
                if (!empty($templatePaths[0])) {
                    $configPath = substr($templatePaths[0], 0, -6) . '_config.php';
                }
            } catch (\Exception $e) {
                $configPath = null;
            }

            if (null === $configPath || !file_exists($configPath)) {
                if (isset($fallbackConfigPaths[$template])) {
                    $configPath = $fallbackConfigPaths[$template];
                } else {
                    continue;
                }
            }

            try {
                $config = include $configPath;
            } catch (\Throwable $exception) {
                $request = $this->requestStack->getCurrentRequest();
                if (null !== $request && 'contao_install' === $request->attributes->get('_route')) {
                    $this->saveToCache = false;
                    continue;
                }

                throw $exception;
            }

            $element = [
                'config' => $config,
                'label' => $config['label'] ?? [implode(' ', array_map('ucfirst', explode('_', substr($template, 5)))), ''],
                'labelPrefix' => '',
                'types' => $config['types'] ?? ['content', 'module', 'form'],
                'contentCategory' => $config['contentCategory'] ?? 'custom_elements',
                'moduleCategory' => $config['moduleCategory'] ?? 'custom_elements',
                'template' => $template,
                'path' => substr(dirname($configPath), strlen($this->projectDir . '/')),
            ];

            if ($element['path'] && str_starts_with($element['path'], 'templates/')) {
                if (isset($themeNamesByTemplateDir[$element['path']])) {
                    $element['labelPrefix'] = $themeNamesByTemplateDir[$element['path']] . ': ';
                }
                else {
                    $element['labelPrefix'] = implode(' ', array_map('ucfirst', preg_split('(\\W)', substr($element['path'], 10)))) . ': ';
                }
            }

            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * @param array $elements
     * @param string $cacheHash
     *
     * @return array
     */
    private function createContent(array $elements, string $cacheHash): array
    {
        $contents = [];
        $contents[] = '<?php' . PHP_EOL;
        $contents[] = '$fileCacheHash = ' . var_export($cacheHash, true) . ';' . PHP_EOL;

        $addLabelPrefix = \count(array_unique(array_map(static fn($element) => $element['path'], $elements))) > 1;

        foreach ($elements as $element) {
            // TL_CTE
            if (in_array('content', $element['types'], true)) {
                $this->addContentElement($element, $addLabelPrefix, $contents);
            }

            // FE_MOD
            if (in_array('module', $element['types'], true)) {
                $this->addModuleElement($element, $addLabelPrefix, $contents);
            }

            // TL_FFL
            if (in_array('form', $element['types'], true)) {
                $this->addFormElement($element, $addLabelPrefix, $contents);
            }

            if (!empty($element['config']['wrapper']['type'])) {
                $GLOBALS['TL_WRAPPERS'][$element['config']['wrapper']['type']][] = $element['template'];
                $contents[] = '$GLOBALS[\'TL_WRAPPERS\'][' . var_export($element['config']['wrapper']['type'], true) . '][] = ' . var_export($element['template'], true) . ';';
            }
        }

        return $contents;
    }

    /**
     * @param array $element
     * @param bool $addLabelPrefix
     * @param array $contents
     *
     * @return void
     */
    private function addContentElement(array $element, bool $addLabelPrefix, array &$contents): void
    {
        $GLOBALS['TL_CTE'][$element['contentCategory']][$element['template']] = 'MadeYourDay\\RockSolidCustomElements\\Element\\CustomElement';
        $contents[] = '$GLOBALS[\'TL_CTE\'][\'' . $element['contentCategory'] . '\'][\'' . $element['template'] . '\'] = \'MadeYourDay\\\\RockSolidCustomElements\\\\Element\\\\CustomElement\';';

        $GLOBALS['TL_LANG']['CTE'][$element['template']] = Translator::translateLabel($element['label']);
        $contents[] = '$GLOBALS[\'TL_LANG\'][\'CTE\'][\'' . $element['template'] . '\'] = \\MadeYourDay\\RockSolidCustomElements\\CustomElement\\Translator::translateLabel(' . var_export($element['label'], true) . ');';

        if ($addLabelPrefix && $element['labelPrefix']) {
            $GLOBALS['TL_LANG']['CTE'][$element['template']][0] = $element['labelPrefix'] . $GLOBALS['TL_LANG']['CTE'][$element['template']][0];
            $contents[] = '$GLOBALS[\'TL_LANG\'][\'CTE\'][\'' . $element['template'] . '\'][0] = ' . var_export($element['labelPrefix'], true) . ' . $GLOBALS[\'TL_LANG\'][\'CTE\'][\'' . $element['template'] . '\'][0];';
        }

        if (!isset($GLOBALS['TL_LANG']['CTE'][$element['contentCategory']])) {
            $GLOBALS['TL_LANG']['CTE'][$element['contentCategory']] = $element['contentCategory'];
        }
        $contents[] = 'if (!isset($GLOBALS[\'TL_LANG\'][\'CTE\'][' . var_export($element['contentCategory'], true) . '])) {';
        $contents[] = '$GLOBALS[\'TL_LANG\'][\'CTE\'][' . var_export($element['contentCategory'], true) . '] = ' . var_export($element['contentCategory'], true) . ';';
        $contents[] = '}';
    }

    /**
     * @param array $element
     * @param bool $addLabelPrefix
     * @param array $contents
     *
     * @return void
     */
    private function addModuleElement(array $element, bool $addLabelPrefix, array &$contents): void
    {
        $GLOBALS['FE_MOD'][$element['moduleCategory']][$element['template']] = 'MadeYourDay\\RockSolidCustomElements\\Element\\CustomElement';
        $contents[] = '$GLOBALS[\'FE_MOD\'][\'' . $element['moduleCategory'] . '\'][\'' . $element['template'] . '\'] = \'MadeYourDay\\\\RockSolidCustomElements\\\\Element\\\\CustomElement\';';

        $GLOBALS['TL_LANG']['FMD'][$element['template']] = Translator::translateLabel($element['label']);
        $contents[] = '$GLOBALS[\'TL_LANG\'][\'FMD\'][\'' . $element['template'] . '\'] = \\MadeYourDay\\RockSolidCustomElements\\CustomElement\\Translator::translateLabel(' . var_export($element['label'], true) . ');';

        if ($addLabelPrefix && $element['labelPrefix']) {
            $GLOBALS['TL_LANG']['FMD'][$element['template']][0] = $element['labelPrefix'] . $GLOBALS['TL_LANG']['FMD'][$element['template']][0];
            $contents[] = '$GLOBALS[\'TL_LANG\'][\'FMD\'][\'' . $element['template'] . '\'][0] = ' . var_export($element['labelPrefix'], true) . ' . $GLOBALS[\'TL_LANG\'][\'FMD\'][\'' . $element['template'] . '\'][0];';
        }

        if (!isset($GLOBALS['TL_LANG']['FMD'][$element['moduleCategory']])) {
            $GLOBALS['TL_LANG']['FMD'][$element['moduleCategory']] = $element['moduleCategory'];
        }
        $contents[] = 'if (!isset($GLOBALS[\'TL_LANG\'][\'FMD\'][' . var_export($element['moduleCategory'], true) . '])) {';
        $contents[] = '$GLOBALS[\'TL_LANG\'][\'FMD\'][' . var_export($element['moduleCategory'], true) . '] = ' . var_export($element['moduleCategory'], true) . ';';
        $contents[] = '}';
    }

    /**
     * @param array $element
     * @param bool $addLabelPrefix
     * @param array $contents
     *
     * @return void
     */
    private function addFormElement(array $element, bool $addLabelPrefix, array &$contents): void
    {
        $hasInput = isset($element['config']['fields']['name']['inputType']) && $element['config']['fields']['name']['inputType'] === 'standardField';

        $GLOBALS['TL_FFL'][$element['template']] = 'MadeYourDay\\RockSolidCustomElements\\Form\\CustomWidget' . ($hasInput ? '' : 'NoInput');
        $contents[] = '$GLOBALS[\'TL_FFL\'][\'' . $element['template'] . '\'] = \'MadeYourDay\\\\RockSolidCustomElements\\\\Form\\\\CustomWidget' . ($hasInput ? '' : 'NoInput') . '\';';

        $GLOBALS['TL_LANG']['FFL'][$element['template']] = Translator::translateLabel($element['label']);
        $contents[] = '$GLOBALS[\'TL_LANG\'][\'FFL\'][\'' . $element['template'] . '\'] = \\MadeYourDay\\RockSolidCustomElements\\CustomElement\\Translator::translateLabel(' . var_export($element['label'], true) . ');';

        if ($addLabelPrefix && $element['labelPrefix']) {
            $GLOBALS['TL_LANG']['FFL'][$element['template']][0] = $element['labelPrefix'] . $GLOBALS['TL_LANG']['FFL'][$element['template']][0];
            $contents[] = '$GLOBALS[\'TL_LANG\'][\'FFL\'][\'' . $element['template'] . '\'][0] = ' . var_export($element['labelPrefix'], true) . ' . $GLOBALS[\'TL_LANG\'][\'FFL\'][\'' . $element['template'] . '\'][0];';
        }
    }
}