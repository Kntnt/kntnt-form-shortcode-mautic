<?php

/**
 * @wordpress-plugin
 * Plugin Name:       Kntnt Form Shortcode for Mautic
 * Plugin URI:        https://github.com/kntnt/kntnt-form-shortcode-mautic
 * GitHub Plugin URI: https://github.com/kntnt/kntnt-form-shortcode-mautic
 * Description:       Allows posting to Mautic from Kntnt Form Shortcode (KFS).
 * Version:           1.0.0
 * Author:            Thomas Barregren
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       kntnt-form-shortcode-mautic
 * Domain Path:       /languages
 * Requires PHP:      7.2
 */

namespace Kntnt\Form_Shortcode_Mautic;

// Uncomment following line to debug this plugin.
define( 'KNTNT_FORM_SHORTCODE_MAUTIC', true );

require 'vendor/autoload.php';

defined( 'WPINC' ) && new Plugin;