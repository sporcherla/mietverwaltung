<?php
/**
 * Minimale Seitenvorlage für das Mieterportal (ohne Theme-Kopf und -Fuß).
 * Wird über den Filter template_include geliefert, wenn die Seite den Portal-Shortcode enthält
 * und die Einstellung portal_vollbild aktiv ist. wp_head()/wp_footer() bleiben erhalten.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'mv-portal-body' ); ?>>
<?php wp_body_open(); ?>
<?php
while ( have_posts() ) {
	the_post();
	echo '<div class="mv-portal-seite">';
	the_content();
	echo '</div>';
}
wp_footer();
?>
</body>
</html>
