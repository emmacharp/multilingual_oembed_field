<?php
	/*
	Copyight: Deux Huit Huit 2015
	License: MIT, see the LICENCE file
	*/

	if(!defined("__IN_SYMPHONY__")) die("<h2>Error</h2><p>You cannot directly access this file</p>");

	class extension_multilingual_oembed_field extends Extension {

		/**
		 * Name of the extension
		 * @var string
		 */
		const EXT_NAME = 'Field: Multilingual oEmbed';
		
		/**
		 * Requires the oembed resources
		 */
		private static function requireoEmbed() {
			require_once(EXTENSIONS . '/multilingual_oembed_field/fields/field.multilingual_oembed.php');
		}

		/* ********* INSTALL/UPDATE/UNISTALL ******* */

		protected static function checkDependency($depname) {
			$status = ExtensionManager::fetchStatus(array('handle' => $depname));
			$status = current($status);
			if ($status != EXTENSION_ENABLED) {
				Administration::instance()->Page->pageAlert("Could not load `$depname` extension.", Alert::ERROR);
				return false;
			}
			return true;
		}

		/**
		 * Creates the table needed for the settings of the field
		 */
		public function install() {
			// depends on "oembed_field"
			if (!static::checkDependency('oembed_field')) {
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
		public function update($previousVersion) {
			self::requireoEmbed();
			$ret = true;
			return $ret;
		}
		
		/**
		 *
		 * Drops the table needed for the settings of the field
		 */
		public function uninstall() {
			self::requireoEmbed();
			$field = FieldMultilingual_oembed::deleteFieldTable();
			return $field;
		}
		
	}