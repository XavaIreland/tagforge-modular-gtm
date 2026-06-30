<?php
/**
 * TagForge Custom Container — Builder Product Template
 *
 * Thin wrapper — all logic lives in [tagforge_builder] shortcode.
 * Applied via template_include filter in child theme functions.php.
 *
 * @package TagForge
 * @since   5.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
echo do_shortcode( '[tagforge_builder]' );
get_footer();
