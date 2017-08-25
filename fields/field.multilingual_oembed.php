<?php
/**
 * Copyright: Deux Huit Huit 2015
 * License: MIT, see the LICENSE file
 */

if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

require_once(EXTENSIONS . '/oembed_field/fields/field.oembed.php');
require_once(EXTENSIONS . '/frontend_localisation/lib/class.FLang.php');

/**
 *
 * Field class that will represent an Multilingual oEmbed resource
 *
 */
class FieldMultilingual_oembed extends FieldOembed
{
    /**
     *
     * Name of the field table
     * @var string
     */
    const FIELD_TBL_NAME = 'tbl_fields_multilingual_oembed';

    /**
     *
     * Constructor for the oEmbed Field object
     * @param mixed $parent
     */
    public function __construct()
    {
        // call the parent constructor
        parent::__construct();
        // set the name of the field
        $this->_name = __('Multilingual oEmbed Resource');
    }

    public function findDefaults(array &$settings)
    {
        parent::findDefaults($settings);
        $settings['default_main_lang'] = 'no';
        $settings['required_languages'] = null;
    }

    public function set($field, $value)
    {
        if ($field == 'required_languages' && !is_array($value)) {
            $value = array_filter(explode(',', $value));
        }

        $this->_settings[$field] = $value;
    }

    public function get($field = null)
    {
        if ($field == 'required_languages') {
            return (array) parent::get($field);
        }

        return parent::get($field);
    }

    /* ********** UTILS *********** */

    /**
     * Returns required languages for this field.
     */
    public function getRequiredLanguages()
    {
        $required = $this->get('required_languages');

        $languages = FLang::getLangs();

        if (in_array('all', $required)) {
            return $languages;
        }

        if (($key = array_search('main', $required)) !== false) {
            unset($required[$key]);

            $required[] = FLang::getMainLang();
            $required   = array_unique($required);
        }

        return $required;
    }

    protected function getLang($data = null)
    {
        $required_languages = $this->getRequiredLanguages();
        $lc = Lang::get();

        if (!FLang::validateLangCode($lc)) {
            $lc = FLang::getLangCode();
        }

        // If value is empty for this language, load value from main language
        if (is_array($data) && $this->get('default_main_lang') == 'yes' && empty($data["value-$lc"])) {
            $lc = FLang::getMainLang();
        }

        // If value if still empty try to use the value from the first
        // required language
        if (is_array($data) && empty($data["value-$lc"]) && count($required_languages) > 0) {
            $lc = $required_languages[0];
        }

        return $lc;
    }


    /* ********** INPUT AND FIELD *********** */


    /**
     *
     * Validates input
     * Called before <code>processRawFieldData</code>
     * @param $data
     * @param $message
     * @param $entry_id
     */
    public function checkPostFieldData($data, &$message, $entry_id = null)
    {
        $error              = self::__OK__;
        $all_langs          = FLang::getAllLangs();
        $main_lang          = FLang::getMainLang();
        $required_languages = $this->getRequiredLanguages();
        $original_required  = $this->get('required');

        foreach (FLang::getLangs() as $lc) {
            $this->set('required', in_array($lc, $required_languages) ? 'yes' : 'no');

            // ignore missing languages
            if (!isset($data[$lc]) && $entry_id) {
                continue;
            }

            // if one language fails, all fail
            if (self::__OK__ != parent::checkPostFieldData($data[$lc], $file_message, $entry_id)) {

                $local_msg = "<br />[$lc] {$all_langs[$lc]}: {$file_message}";

                if ($lc === $main_lang) {
                    $message = $local_msg . $message;
                }
                else {
                    $message = $message . $local_msg;
                }

                $error = self::__ERROR__;
            }
        }

        $this->set('required', $original_required);

        return $error;
    }


    /**
     *
     * Utility function to check if the $url param
     * is not already in the DB for this field
     * @param $url
     * @param $entry_id
     */
    protected function checkUniqueness($url, $entry_id = null)
    {
        $id = $this->get('field_id');

        $query = "
            SELECT count(`id`) as `c` FROM `tbl_entries_data_$id`
            WHERE (`url` = '$url' 
        ";
        foreach (FLang::getLangs() as $lc) {
            $query .= " OR `url-$lc` = '$url' ";
        }
        $query .= ')';

        if ($entry_id != null) {
            $query .= " AND `entry_id` != $entry_id";
        }

        $count = Symphony::Database()->fetchVar('c', 0, $query);

        return $count == null || $count == 0;
    }

    /**
     *
     * Process data before saving into databse.
     * Also,
     * Fetches oEmbed data from the source
     *
     * @param array $data
     * @param int $status
     * @param boolean $simulate
     * @param int $entry_id
     *
     * @return Array - data to be inserted into DB
     */
    public function processRawFieldData($data, &$status, &$message = null, $simulate = false, $entry_id = null)
    {
        if (!is_array($data)) {
            $data = array();
        }

        $status     = self::__OK__;
        $result     = array();
        $field_data = $data;

        $missing_langs = array();
        $messages = array();
        $statuses = array();
        $row = array();

        foreach (FLang::getLangs() as $lc) {
            if (!isset($field_data[$lc])) {
                $missing_langs[] = $lc;
                continue;
            }

            $data = $field_data[$lc];
            
            $result[$lc] = parent::processRawFieldData($data, $status, $message, $simulate, $entry_id);
            $messages[$lc] = $message;
            $statuses[$lc] = $status;
            
            $row["url-$lc"] = $data;
            $row["res_id-$lc"] = $result[$lc]['res_id'];
            $row["url_oembed_xml-$lc"] = $result[$lc]['url_oembed_xml'];
            $row["oembed_xml-$lc"] = $result[$lc]['oembed_xml'];
            $row["title-$lc"] = $result[$lc]['title'];
            $row["thumbnail_url-$lc"] = $result[$lc]['thumbnail_url'];
            $row["driver-$lc"] = $result[$lc]['driver'];
            
            if ($lc == FLang::getMainLang()) {
                $row["url"] = $data;
                $row["res_id"] = $result[$lc]['res_id'];
                $row["url_oembed_xml"] = $result[$lc]['url_oembed_xml'];
                $row["oembed_xml"] = $result[$lc]['oembed_xml'];
                $row["title"] = $result[$lc]['title'];
                $row["thumbnail_url"] = $result[$lc]['thumbnail_url'];
                $row["driver"] = $result[$lc]['driver'];
            }
        }
        
        // fix output
        if (in_array(self::__ERROR__, $statuses)) {
            $status = self::__ERROR__;
        }
        $message = implode('. ', $messages);
        
        // return row
        return $row;
    }

    /**
     * This function permits parsing different field settings values
     *
     * @param array $settings
     *  the data array to initialize if necessary.
     */
    public function setFromPOST(Array $settings = array())
    {
        // call the default behavior
        parent::setFromPOST($settings);
        
        $settings = $this->get();
        
        // declare a new setting array
        $new_settings = array();
        $new_settings['unique'] = $this->get('unique');
        $new_settings['thumbs'] = $this->get('thumbs');
        $new_settings['driver'] = $this->get('driver');
        $new_settings['query_params'] = $this->get('query_params');
        $new_settings['force_ssl'] = $this->get('force_ssl');
        $new_settings['unique_media'] = $this->get('unique_media');

        // set new settings
        $new_settings['default_main_lang'] =  ( isset($settings['default_main_lang'])   && $settings['default_main_lang'] == 'on' ? 'yes' : 'no');
        $new_settings['required_languages'] = ( isset($settings['required_languages'])  && is_array($settings['required_languages'])
            ? implode(',', $settings['required_languages'])
            : null
        );

        // save it into the array
        $this->setArray($new_settings);
    }


    
    /**
     *
     * Save field settings into the field's table
     */
    public function commit()
    {
        $required_languages = $this->get('required_languages');
        // all are required
        if (in_array('all', $required_languages)) {
            $this->set('required', 'yes');
            $required_languages = array('all');
        }
        else {
            $this->set('required', 'no');
        }

        // if main is required, remove the actual language code
        if (in_array('main', $required_languages)) {
            if (($key = array_search(FLang::getMainLang(), $required_languages)) !== false) {
                unset($required_languages[$key]);
            }
        }

        $this->set('required_languages', $required_languages);
        
        // if the default implementation works...
        if(!parent::commit()) return false;
        
        $id = $this->get('id');

        // exit if there is no id
        if($id == false) return false;

        // declare an array contains the field's settings
        $default_main_lang = $this->get('default_main_lang');

        $tbl = self::FIELD_TBL_NAME;
        
        // return if the SQL command was successful
        return Symphony::Database()->query(sprintf("
            UPDATE
                `$tbl`
            SET
                `default_main_lang` = '%s',
                `required_languages` = '%s'
            WHERE
                `field_id` = '%s';",
            $default_main_lang === 'yes' ? 'yes' : 'no',
            implode(',', $required_languages),
            $id
        ));
    }




    /* ******* DATA SOURCE ******* */

    /**
     * Appends data into the XML tree of a Data Source
     * @param $wrapper
     * @param $data
     */
    public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null)
    {
        // all-languages
        $all_languages = strpos($mode, 'all-languages');

        if ($all_languages !== false) {
            $submode = substr($mode, $all_languages + 15);

            $all = new XMLElement($this->get('element_name'), null, array('mode' => $mode));

            foreach (FLang::getLangs() as $lc) {
                $data['res_id']          = $data["res_id-$lc"];
                $data['url']             = $data["url-$lc"];
                $data['url_oembed_xml']  = $data["url_oembed_xml-$lc"];
                $data['title']           = $data["title-$lc"];
                $data['thumbnail_url']   = $data["thumbnail_url-$lc"];
                $data['oembed_xml']      = $data["oembed_xml-$lc"];
                $data['driver']          = $data["driver-$lc"];

                $item = new XMLElement('item', null, array('lang' => $lc));

                parent::appendFormattedElement($item, $data, $encode, $submode);
                $all->appendChild($item);
            }

            $wrapper->appendChild($all);
        }

        // current-language
        else {
            $lc = FLang::getLangCode();

            // If value is empty for this language, load value from main language
            if ($this->get('default_main_lang') == 'yes' && empty($data["url-$lc"])) {
                $lc = FLang::getMainLang();
            }

            $data['res_id']          = $data["res_id-$lc"];
            $data['url']             = $data["url-$lc"];
            $data['url_oembed_xml']  = $data["url_oembed_xml-$lc"];
            $data['title']           = $data["title-$lc"];
            $data['thumbnail_url']   = $data["thumbnail_url-$lc"];
            $data['oembed_xml']      = $data["oembed_xml-$lc"];
            $data['driver']          = $data["driver-$lc"];

            parent::appendFormattedElement($wrapper, $data, $encode, $mode);
            $elem = $wrapper->getChildByName($this->get('element_name'), 0);
        }
    }




    /* ********* UI *********** */

    /**
     *
     * Builds the UI for the publish page
     * @param XMLElement $wrapper
     * @param mixed $data
     * @param mixed $flagWithError
     * @param string $fieldnamePrefix
     * @param string $fieldnamePostfix
     */
    public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null)
    {
        Extension_Frontend_Localisation::appendAssets();
        extension_multilingual_oembed_field::appendHeaders(extension_multilingual_oembed_field::PUBLISH_HEADERS);
        $main_lang = FLang::getMainLang();
        $all_langs = FLang::getAllLangs();
        $langs     = FLang::getLangs();

        $errors = !$flagWithError ? array() : array_reduce(explode('.', $flagWithError), function ($carry, $item) {
            $matches = array();
            if (preg_match('/\[([a-z]+)\] (.+)/', $item, $matches) === 1) {
                $carry[trim($matches[1])] = trim($matches[2]);
            }
            return $carry;
        }, array());

        $wrapper->setAttribute('class', $wrapper->getAttribute('class') . ' field-multilingual field-oembed field-multilingual-oembed');
        $container = new XMLElement('div', null, array('class' => 'container'));

        /*------------------------------------------------------------------------------------------------*/
        /*  Label  */
        /*------------------------------------------------------------------------------------------------*/

        $label    = Widget::Label($this->get('label'));
        $optional = '';
        $required_languages = $this->getRequiredLanguages();
        
        $required = in_array('all', $required_languages) || count($langs) == count($required_languages);

        if (!$required) {
            if (empty($required_languages)) {
                $optional .= __('All languages are optional');
            } else {
                $optional_langs = array();
                foreach ($langs as $lang) {
                    if (!in_array($lang, $required_languages)) {
                        $optional_langs[] = $all_langs[$lang];
                    }
                }
                
                foreach ($optional_langs as $idx => $lang) {
                    $optional .= ' ' . __($lang);
                    if ($idx < count($optional_langs) - 2) {
                        $optional .= ',';
                    } else if ($idx < count($optional_langs) - 1) {
                        $optional .= ' ' . __('and');
                    }
                }
                if (count($optional_langs) > 1) {
                    $optional .= __(' are optional');
                } else {
                    $optional .= __(' is optional');
                }
            }
        }

        if ($optional !== '') {
            foreach ($langs as $lc) {
                $label->appendChild(new XMLElement('i', $optional, array(
                    'class'          => "tab-element tab-$lc",
                    'data-lang_code' => $lc
                )));
            }
        }

        $container->appendChild($label);
        
        /*------------------------------------------------------------------------------------------------*/
        /*  Tabs  */
        /*------------------------------------------------------------------------------------------------*/

        $ul = new XMLElement('ul', null, array('class' => 'tabs'));
        foreach ($langs as $lc) {
            $li = new XMLElement('li', $all_langs[$lc], array('class' => $lc));
            $lc === $main_lang ? $ul->prependChild($li) : $ul->appendChild($li);
        }

        $container->appendChild($ul);

        /*------------------------------------------------------------------------------------------------*/
        /*  Panels  */
        /*------------------------------------------------------------------------------------------------*/

        $label_text = $this->get('label');
        $this->set('label', null);
        $this->set('required', true);
        foreach ($langs as $lc) {
            $div = new XMLElement('div', null, array(
                'class'          => 'tab-panel tab-' . $lc,
                'data-lang_code' => $lc
            ));

            $element_name = $this->get('element_name');
            
            $translatedData = array(
                'res_id' => $data["res_id-$lc"],
                'url_oembed_xml' => $data["url_oembed_xml-$lc"],
                'title' => $data["title-$lc"],
                'url' => $data["url-$lc"],
                'oembed_xml' => $data["oembed_xml-$lc"],
                'thumbnail_url' => $data["thumbnail_url-$lc"],
                'driver' => $data["driver-$lc"]
            );
            parent::displayPublishPanel(
                $div,
                $translatedData,
                !isset($errors[$lc]) ? null : $errors[$lc],
                $fieldnamePrefix,
                $fieldnamePostfix . "[$lc]"
            );
            $container->appendChild($div);
        }
        $this->set('label', $label_text);
        
        /*------------------------------------------------------------------------------------------------*/
        /*  Errors  */
        /*------------------------------------------------------------------------------------------------*/

        if ($flagWithError != null) {
            $wrapper->appendChild(Widget::Error($container, $flagWithError));
        }
        else {
            $wrapper->appendChild($container);
        }
    }

    /**
     *
     * Builds the UI for the field's settings when creating/editing a section
     * @param XMLElement $wrapper
     * @param array $errors
     */
    public function displaySettingsPanel(XMLElement &$wrapper, $errors = null)
    {
        extension_multilingual_oembed_field::appendHeaders(extension_multilingual_oembed_field::SETTINGS_HEADERS);
        
        /* first line, label and such */
        parent::displaySettingsPanel($wrapper, $errors);
        
        // remove required checkbox
        $lastdiv = $wrapper->getNumberOfChildren() - 1;
        $wrapper->getChild($lastdiv)->removeChildAt(2);
        
        $two_columns = new XMLELement('div', null, array('class' => 'two columns'));
        
        // Require all languages && Require custom languages
        $this->settingsRequiredLanguages($two_columns);

        // Default to main lang && Display in entries table
        $this->settingsDefaultMainLang($two_columns);

        $wrapper->appendChild($two_columns);
    }
    
    private function settingsDefaultMainLang(XMLElement &$wrapper)
    {
        $name = "fields[{$this->get('sortorder')}][default_main_lang]";

        $wrapper->appendChild(Widget::Input($name, 'no', 'hidden'));

        $label = Widget::Label();
        $label->setAttribute('class', 'column');
        $input = Widget::Input($name, 'on', 'checkbox');

        if ($this->get('default_main_lang') == 'yes') {
            $input->setAttribute('checked', 'checked');
        }

        $label->setValue(__('%s Use value from main language if selected language has empty value.', array($input->generate())));

        $wrapper->appendChild($label);
    }

    private function settingsRequiredLanguages(XMLElement &$wrapper)
    {
        $name = "fields[{$this->get('sortorder')}][required_languages][]";

        $required_languages = $this->get('required_languages');

        $displayed_languages = FLang::getLangs();

        if (($key = array_search(FLang::getMainLang(), $displayed_languages)) !== false) {
            unset($displayed_languages[$key]);
        }

        $options = Extension_Languages::findOptions($required_languages, $displayed_languages);

        array_unshift(
            $options,
            array('all', $this->get('required') == 'yes', __('All')),
            array('main', in_array('main', $required_languages), __('Main language'))
        );

        $label = Widget::Label(__('Required languages'));
        $label->setAttribute('class', 'column');
        $label->appendChild(
            Widget::Select($name, $options, array('multiple' => 'multiple'))
        );

        $wrapper->appendChild($label);
    }

    /**
     *
     * Build the UI for the table view
     * @param Array $data
     * @param XMLElement $link
     * @param int $entry_id
     * @return string - the html of the link
     */
    public function prepareTableValue($data, XMLElement $link = null, $entry_id = null)
    {
        $lc = $this->getLang();
        $useMain = $this->get('default_main_lang') == 'yes';
        
        $translatedData = array(
            'title' => $useMain && empty($data["title-$lc"]) ? $data['title'] : $data["title-$lc"],
            'url' => $useMain && empty($data["url-$lc"]) ? $data['url'] : $data["url-$lc"],
            'thumbnail_url' => $useMain && empty($data["thumbnail_url-$lc"]) ? $data['thumbnail_url'] : $data["thumbnail_url-$lc"],
        );
        return parent::prepareTableValue($translatedData, $link, $entry_id);
    }

    /**
     *
     * Return a plain text representation of the field's data
     * @param array $data
     * @param int $entry_id
     */
    public function prepareTextValue($data, $entry_id = null)
    {
        $lc = $this->getLang();
        $useMain = $this->get('default_main_lang') == 'yes';
        $translatedData = array(
            'title' => $useMain && empty($data["title-$lc"]) ? $data['title'] : $data["title-$lc"],
            'url' => $useMain && empty($data["url-$lc"]) ? $data['url'] : $data["url-$lc"],
        );
        return parent::prepareTextValue($translatedData, $entry_id);
    }



    /* ********* SQL Data Definition ************* */

    public static function generateTableColumns($langs = null)
    {
        $cols = array();
        if (!is_array($langs)) {
            $langs = FLang::getLangs();
        }
        foreach ($langs as $lc) {
            $cols[] = "`res_id-{$lc}`           VARCHAR(128), ";
            $cols[] = "`url-{$lc}`              TEXT, ";
            $cols[] = "`url_oembed_xml-{$lc}`   TEXT, ";
            $cols[] = "`title-{$lc}`            TEXT, ";
            $cols[] = "`thumbnail_url-{$lc}`    TEXT, ";
            $cols[] = "`oembed_xml-{$lc}`       TEXT, ";
            $cols[] = "`driver-{$lc}`           VARCHAR(50),";
        }
        return $cols;
    }

    public static function generateTableKeys()
    {
        $keys = array();
        foreach (FLang::getLangs() as $lc) {
            $keys[] = "KEY `res_id-{$lc}` (`res_id-{$lc}`),";
        }
        return $keys;
    }

    /**
     * Creates table needed for entries of individual fields
     */
    public function createTable()
    {
        $field_id = $this->get('id');

        $query = "
            CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
                `id` INT(11)        UNSIGNED NOT NULL AUTO_INCREMENT,
                `entry_id`          INT(11) UNSIGNED NOT NULL,
                `res_id`            VARCHAR(128),
                `url`               VARCHAR(2048),
                `url_oembed_xml`    VARCHAR(2048),
                `title`             VARCHAR(2048),
                `thumbnail_url`     VARCHAR(2048),
                `oembed_xml`        TEXT,
                `dateCreated`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `driver`            VARCHAR(50),";

        $query .= implode('', self::generateTableColumns());

        $query .= "
                PRIMARY KEY (`id`),
                UNIQUE KEY `entry_id` (`entry_id`),";

        $query .= implode('', self::generateTableKeys());
        
        $query .= " KEY `res_id` (`res_id`)";

        $query .= "
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";

        return Symphony::Database()->query($query);
    }

    /**
     * Creates the table needed for the settings of the field
     */
    public static function createFieldTable()
    {
        $tbl = self::FIELD_TBL_NAME;
        return Symphony::Database()->query("
            CREATE TABLE IF NOT EXISTS `$tbl` (
                `id`                    INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `field_id`              INT(11) UNSIGNED NOT NULL,
                `refresh`               INT(11) UNSIGNED NULL,
                `driver`                VARCHAR(250) NOT NULL,
                `unique`                ENUM('yes','no') NOT NULL DEFAULT 'no',
                `thumbs`                ENUM('yes','no') NOT NULL DEFAULT 'no',
                `query_params`          VARCHAR(1024) NULL,
                `force_ssl`             ENUM('yes','no') NOT NULL DEFAULT 'no',
                `unique_media`          ENUM('yes','no') NOT NULL DEFAULT 'no',
                `default_main_lang`     ENUM('yes', 'no') DEFAULT 'no',
                `required_languages`    VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `field_id` (`field_id`)
            )  ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ");
    }
    
    /**
     *
     * Drops the table needed for the settings of the field
     */
    public static function deleteFieldTable()
    {
        $tbl = self::FIELD_TBL_NAME;
        return Symphony::Database()->query("
            DROP TABLE IF EXISTS `$tbl`
        ");
    }
    
}
