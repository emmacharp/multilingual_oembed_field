<?php
/**
 * Copyright: Deux Huit Huit 2015
 * License: MIT, see the LICENSE file
 */

if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

require_once(EXTENSIONS . '/oembed_field/fields/field.oembed.php');
require_once(EXTENSIONS . '/frontend_localisation/lib/class.FLang.php');

require_once EXTENSIONS . '/oembed_field/lib/class.entryqueryoembedadapter.php';
require_once EXTENSIONS . '/multilingual_oembed_field/lib/class.entryquerymultilingualoembedadapter.php';

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
        // set the EQFA
        $this->entryQueryFieldAdapter = new EntryQueryMultilingualoEmbedAdapter($this);
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

        $q = Symphony::Database()
            ->select(['count(id)' => 'c'])
            ->from('tbl_entries_data_' . $id);
        $urls = array();

        foreach (FLang::getLangs() as $lc) {
            $urls['url-' . $lc] = $url;
        }

        $q->where(['or' => [
            array_merge(['url' => $url], $urls)
        ]]);

        if ($entry_id != null) {
            $q->where(['entry_id' => ['!=' => $entry_id]]);
        }

        $count = $q
            ->execute()
            ->variable('c');

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
        return Symphony::Database()
            ->update($tbl)
            ->set([
                'default_main_lang' => $default_main_lang === 'yes' ? 'yes' : 'no',
                'required_languages' => implode(',', $required_languages),
            ])
            ->where(['field_id' => $id])
            ->execute()
            ->success();
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

        $wrapper->setAttribute('class', $wrapper->getAttribute('class') . ' field-multilingual field-oembed field-multilingual-oembed');

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


        if ($flagWithError != null) {
            $wrapper->appendChild(Widget::Error($label, $flagWithError));
        }
        else {
            $wrapper->appendChild($label);
        }

        /*------------------------------------------------------------------------------------------------*/
        /*  Tabs  */
        /*------------------------------------------------------------------------------------------------*/

        $ul = new XMLElement('ul', null, array('class' => 'tabs multilingualtabs'));
        foreach ($langs as $lc) {
            $li = new XMLElement('li', $lc, array('class' => $lc));
            $lc === $main_lang ? $ul->prependChild($li) : $ul->appendChild($li);
        }

        $wrapper->appendChild($ul);

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
                null,
                $fieldnamePrefix,
                $fieldnamePostfix . "[$lc]"
            );
            $wrapper->appendChild($div);
        }
        $this->set('label', $label_text);

        /*------------------------------------------------------------------------------------------------*/
        /*  Errors  */
        /*------------------------------------------------------------------------------------------------*/

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
            $cols['res_id-' . $lc] = [
                'type' => 'varchar(128)',
                'null' => true,
            ];
            $cols['url-' . $lc] = [
                'type' => 'text',
                'null' => true,
            ];
            $cols['url_oembed_xml-' . $lc] = [
                'type' => 'text',
                'null' => true,
            ];
            $cols['title-' . $lc] = [
                'type' => 'text',
                'null' => true,
            ];
            $cols['thumbnail_url-' . $lc] = [
                'type' => 'text',
                'null' => true,
            ];
            $cols['oembed_xml-' . $lc] = [
                'type' => 'text',
                'null' => true,
            ];
            $cols['driver-' . $lc] = [
                'type' => 'varchar(50)',
                'null' => true,
            ];
        }
        return $cols;
    }

    public static function generateTableKeys()
    {
        $keys = array();
        foreach (FLang::getLangs() as $lc) {
            $keys['res_id-' . $lc] = 'key';
        }
        return $keys;
    }

    /**
     * Creates table needed for entries of individual fields
     */
    public function createTable()
    {
        return Symphony::Database()
            ->create('tbl_entries_data_' . $this->get('id'))
            ->ifNotExists()
            ->fields(array_merge([
                'id' => [
                    'type' => 'int(11)',
                    'auto' => true,
                ],
                'entry_id' => 'int(11)',
                'res_id' => [
                    'type' => 'varchar(128)',
                    'null' => true,
                ],
                'url' => [
                    'type' => 'varchar(2048)',
                    'null' => true,
                ],
                'url_oembed_xml' => [
                    'type' => 'varchar(2048)',
                    'null' => true,
                ],
                'title' => [
                    'type' => 'varchar(2048)',
                    'null' => true,
                ],
                'thumbnail_url' => [
                    'type' => 'varchar(2048)',
                    'null' => true,
                ],
                'oembed_xml' => [
                    'type' => 'text',
                    'null' => true,
                ],
                'dateCreated' => [
                    'type' => 'timestamp',
                    'default' => 'current_timestamp',
                ],
                'driver' => [
                    'type' => 'varchar(50)',
                    'null' => true,
                ],
            ], self::generateTableColumns()))
            ->keys(array_merge([
                'id' => 'primary',
                'entry_id' => 'unique',
                'res_id' => 'key',
            ], self::generateTableKeys()))
            ->execute()
            ->success();
    }

    /**
     * Creates the table needed for the settings of the field
     */
    public static function createFieldTable()
    {

        return Symphony::Database()
            ->create(self::FIELD_TBL_NAME)
            ->ifNotExists()
            ->fields([
                'id' => [
                    'type' => 'int(11)',
                    'auto' => true,
                ],
                'field_id' => 'int(11)',
                'refresh' => [
                    'type' => 'int(11)',
                    'null' => true,
                ],
                'driver' => 'varchar(250)',
                'unique' => [
                    'type' => 'enum',
                    'values' => ['yes','no'],
                    'default' => 'no',
                ],
                'thumbs' => [
                    'type' => 'enum',
                    'values' => ['yes','no'],
                    'default' => 'no',
                ],
                'query_params' => [
                    'type' => 'varchar(2014)',
                    'null' => true,
                ],
                'force_ssl' => [
                    'type' => 'enum',
                    'values' => ['yes','no'],
                    'default' => 'no',
                ],
                'unique_media' => [
                    'type' => 'enum',
                    'values' => ['yes','no'],
                    'default' => 'no',
                ],
                'default_main_lang' => [
                    'type' => 'enum',
                    'values' => ['yes','no'],
                    'default' => 'no',
                ],
                'required_languages' => [
                    'type' => 'varchar(255)',
                    'null' => true,
                ],
            ])
            ->keys([
                'id' => 'primary',
                'field_id' => 'unique',
            ])
            ->execute()
            ->success();
    }

    /**
     *
     * Drops the table needed for the settings of the field
     */
    public static function deleteFieldTable()
    {
        return Symphony::Database()
            ->drop(self::FIELD_TBL_NAME)
            ->ifExists()
            ->execute()
            ->success();
    }

}
