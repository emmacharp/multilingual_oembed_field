<?php

/**
 * @package toolkit
 */
/**
 * Specialized EntryQueryFieldAdapter that facilitate creation of queries filtering/sorting data from
 * an Multilingual oEmbed Field.
 * @see FieldMultilingualoEmbed
 * @since Symphony 3.0.0
 */
class EntryQueryMultilingualoEmbedAdapter extends EntryQueryoEmbedAdapter
{
    public function getFilterColumns()
    {
        $lc = FLang::getLangCode();

        if ($lc) {
            return ["url-$lc", "title-$lc", "driver-$lc"];
        }

        return parent::getFilterColumns();
    }

    public function getSortColumns()
    {
        $lc = FLang::getLangCode();

        if ($lc) {
            return ["title-$lc"];
        }

        return parent::getSortColumns();
    }
}
