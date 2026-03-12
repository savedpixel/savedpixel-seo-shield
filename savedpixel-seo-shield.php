<?php
/**
 * Plugin Name: SavedPixel SEO Shield
 * Plugin URI: https://github.com/savedpixel
 * Description: Block junk search traffic, harden public SEO endpoints, and control crawl-facing protections from one SavedPixel admin page.
 * Version: 1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Byron Jacobs
 * Author URI: https://github.com/savedpixel
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: savedpixel-seo-shield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/savedpixel-admin-shared.php';
require_once __DIR__ . '/includes/class-savedpixel-seo-shield.php';

savedpixel_register_admin_preview_asset(
	plugin_dir_url( __FILE__ ) . 'assets/css/savedpixel-admin-preview.css',
	SavedPixel_Seo_Shield::VERSION,
	array( 'savedpixel', 'savedpixel-seo-shield' )
);

SavedPixel_Seo_Shield::bootstrap();

register_activation_hook( __FILE__, array( 'SavedPixel_Seo_Shield', 'activate' ) );
