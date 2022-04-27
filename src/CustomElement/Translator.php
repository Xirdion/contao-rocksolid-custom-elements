<?php

namespace MadeYourDay\RockSolidCustomElements\CustomElement;

class Translator
{
    /**
     * Return translated label if label configuration contains language keys.
     *
     * @param array|mixed $labelConfig
     *
     * @return array|mixed
     */
    public static function translateLabel($labelConfig)
    {
        if (!is_array($labelConfig)) {
            return $labelConfig;
        }

        // Return if it isn't an associative array
        if (!count(array_filter(array_keys($labelConfig), 'is_string'))) {
            return $labelConfig;
        }

        $language = str_replace('-', '_', $GLOBALS['TL_LANGUAGE']);
        if (isset($labelConfig[$language])) {
            return $labelConfig[$language];
        }

        // Try the short language code
        $language = substr($language, 0, 2);
        if (isset($labelConfig[$language])) {
            return $labelConfig[$language];
        }

        // Fall back to english
        $language = 'en';
        if (isset($labelConfig[$language])) {
            return $labelConfig[$language];
        }

        // Return the first item that seems to be a language key
        foreach ($labelConfig as $key => $label) {
            if (strlen($key) === 2 || substr($key, 2, 1) === '_') {
                return $label;
            }
        }

        return $labelConfig;
    }
}