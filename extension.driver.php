<?php
/**
 * Copyright: Deux Huit Huit 2015
 * License: MIT, see the LICENSE file
 */

if (!defined("__IN_SYMPHONY__")) die("<h2>Error</h2><p>You cannot directly access this file</p>");

class extension_multilingual_oembed_field extends Extension
{
    private static $appendedHeaders = 0;
    const PUBLISH_HEADERS = 1;
    const SETTINGS_HEADERS = 2;

    /**
     * Name of the extension
     * @var string
     */
    const EXT_NAME = 'Field: Multilingual oEmbed';

    /**
     * Requires the oembed resources
     */
    private static function requireoEmbed()
    {
        require_once(EXTENSIONS . '/multilingual_oembed_field/fields/field.multilingual_oembed.php');
    }

    /**
     * Add headers to the page.
     *
     * @param $type
     */
    public static function appendHeaders($type)
    {
        if (
            (self::$appendedHeaders & $type) !== $type
            && class_exists('Administration')
            && Administration::instance() instanceof Administration
            && Administration::instance()->Page instanceof HTMLPage
        ) {
            $page = Administration::instance()->Page;

            if ($type === self::PUBLISH_HEADERS) {
                $page->addStylesheetToHead(URL . '/extensions/multilingual_oembed_field/assets/multilingual_oembed_field.publish.css', 'screen');
                $page->addScriptToHead(URL . '/extensions/multilingual_oembed_field/assets/multilingual_oembed_field.publish.js');
            }

            if ($type === self::SETTINGS_HEADERS) {
                $page->addScriptToHead(URL . '/extensions/multilingual_oembed_field/assets/multilingual_oembed_field.settings.js');
            }

            self::$appendedHeaders &= $type;
        }
    }

    /* ********* INSTALL/UPDATE/UNISTALL ******* */

    protected static function checkDependency($depname)
    {
        $status = Symphony::ExtensionManager()->fetchStatus(array('handle' => $depname));
        $status = current($status);

        if ($status != Extension::EXTENSION_ENABLED) {
            Administration::instance()->Page->pageAlert("Could not load `$depname` extension.", Alert::ERROR);
            return false;
        }
        return true;
    }

    protected static function checkDependencyVersion($depname, $version)
    {
        $installedVersion = ExtensionManager::fetchInstalledVersion($depname);
        if (version_compare($installedVersion, $version) == -1) {
            Administration::instance()->Page->pageAlert("Extension `$depname` must have version $version or newer.", Alert::ERROR);
            return false;
        }
        return true;
    }

    /**
     * Creates the table needed for the settings of the field
     */
    public function install()
    {
        // depends on "oembed_field"
        if (!static::checkDependency('oembed_field')) {
            return false;
        }
        if (!static::checkDependencyVersion('oembed_field', '1.8.9')) {
            return false;
        }
        // depends on "languages"
        if (!static::checkDependency('languages')) {
            return false;
        }
        // depends on "frontend_localisation"
        if (!static::checkDependency('frontend_localisation')) {
            return false;
        }
        self::requireoEmbed();
        $create = FieldMultilingual_oembed::createFieldTable();
        return $create;

    }

    /**
     * Creates the table needed for the settings of the field
     */
    public function update($previousVersion = false)
    {
        self::requireoEmbed();
        $ret = true;
        return $ret;
    }

    /**
     *
     * Drops the table needed for the settings of the field
     */
    public function uninstall()
    {
        self::requireoEmbed();
        $field = FieldMultilingual_oembed::deleteFieldTable();
        return $field;
    }



    /*------------------------------------------------------------------------------------------------*/
    /*  Delegates  */
    /*------------------------------------------------------------------------------------------------*/

    public function getSubscribedDelegates()
    {
        return array(
            array(
                'page'     => '/system/preferences/',
                'delegate' => 'AddCustomPreferenceFieldsets',
                'callback' => 'dAddCustomPreferenceFieldsets'
            ),
            array(
                'page'     => '/system/preferences/',
                'delegate' => 'Save',
                'callback' => 'dSave'
            ),
            array(
                'page'     => '/extensions/frontend_localisation/',
                'delegate' => 'FLSavePreferences',
                'callback' => 'dFLSavePreferences'
            ),
        );
    }



    /*------------------------------------------------------------------------------------------------*/
    /*  System preferences  */
    /*------------------------------------------------------------------------------------------------*/

    /**
     * Display options on Preferences page.
     *
     * @param array $context
     */
    public function dAddCustomPreferenceFieldsets($context)
    {
        $group = new XMLElement('fieldset');
        $group->setAttribute('class', 'settings');
        $group->appendChild(new XMLElement('legend', __(self::EXT_NAME)));

        $label = Widget::Label(__('Consolidate entry data'));
        $label->prependChild(Widget::Input('settings[multilingual_oembed][consolidate]', 'yes', 'checkbox', array('checked' => 'checked')));
        $group->appendChild($label);
        $group->appendChild(new XMLElement('p', __('Check this field if you want to consolidate database by <b>keeping</b> entry values of removed/old Language Driver language codes. Entry values of current language codes will not be affected.'), array('class' => 'help')));

        $context['wrapper']->appendChild($group);
    }

    /**
     * Edits the preferences to be saved
     *
     * @param array $context
     */
    public function dSave($context) {
        // prevent the saving of the values
        unset($context['settings']['multilingual_oembed']);
    }

    /**
     * Save options from Preferences page
     *
     * @param array $context
     */
    public function dFLSavePreferences($context)
    {
        self::requireoEmbed();
        if ($fields = Symphony::Database()
            ->select(['field_id'])
            ->from(FieldMultilingual_oembed::FIELD_TBL_NAME)
            ->execute()
            ->rows()
        ) {
            $new_languages = $context['new_langs'];

            // Foreach field check multilanguage values foreach language
            foreach ($fields as $field) {
                $entries_table = "tbl_entries_data_{$field["field_id"]}";

                try {
                    $current_columns = Symphony::Database()
                        ->showColumns()
                        ->from($entries_table)
                        ->like('url-%')
                        ->execute()
                        ->success();
                } catch (DatabaseException $dbe) {
                    // Field doesn't exist. Better remove it's settings
                    Symphony::Database()
                        ->delete(FieldMultilingual_oembed::FIELD_TBL_NAME)
                        ->where(['field_id' => $field['field_id']])
                        ->execute()
                        ->success();

                    continue;
                }

                $valid_columns = array();

                // Remove obsolete fields
                if ($current_columns) {
                    $consolidate = $_POST['settings']['multilingual_oembed']['consolidate'] === 'yes';

                    foreach ($current_columns as $column) {
                        $column_name = $column['Field'];

                        $lc = str_replace('url-', '', $column_name);

                        // If not consolidate option AND column lang_code not in supported languages codes -> drop Column
                        if (!$consolidate && !in_array($lc, $new_languages)) {
                            Symphony::Dabatase()
                                ->alter($entries_table)
                                ->drop([
                                    'res_id-' . $lc,
                                    'url-' . $lc,
                                    'url_oembed_xml-' . $lc,
                                    'title-' . $lc,
                                    'thumbnail_url-' . $lc,
                                    'oembed_xml-' . $lc,
                                    'driver-' . $lc,
                                ])
                                ->execute()
                                ->success();
                        }
                        else {
                            $valid_columns[] = $column_name;
                        }
                    }
                }

                // Add new fields
                foreach ($new_languages as $lc) {
                    // if columns for language don't exist, create them
                    if (!in_array("url-$lc", $valid_columns)) {
                        Symphony::Database()
                            ->alter($entries_table)
                            ->add(FieldMultilingual_oembed::generateTableColumns())
                            ->execute()
                            ->success();
                    }
                }
            }
        }
    }
}
