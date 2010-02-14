===Plugin Name===
Taxonomy Images

Contributors: mfields
Donate link: http://mfields.org/donate/
Tags: taxonomy, tag, category, image, upload, media
Requires at least: 2.9.1
Tested up to: 2.9.1
Stable tag: trunk

Enables users to associate images in their Media Library to categories, tags and taxonomies.

==Description==

The Taxonomy Images plugin allows users to associate images from the Media Library to categories, tags and custom taxonomies. For usage instructions please view the [screencast](http://screenr.com/zMx). To display the images in your theme, you will want to use the following code in the appropriate theme file. The correct file will vary depending on your theme. category.php, tag.php, archive.php are a few file that this code will work in. Please see [Template Hierarchy](http://codex.wordpress.org/Template_Hierarchy) for more information.

`<?php do_action( 'taxonomy_image_plugin_print_image_html', 'detail' ); ?>`

Here we have passed to arguments to the WordPress core function do_action(). The first is `taxonomy_image_plugin_print_image_html` and should not be changed. The second represents the size of the image that you would like displayed. Acceptable values are: 

* detail
* thumbnail
* medium
* large
* fullsize

==Installation==
1. Download
1. Unzip the package and upload to your /wp-content/plugins/ directory.
1. Log into WordPress and navigate to the "Plugins" panel.
1. Activate the plugin.

==Changelog==

= 0.2 =
* Original Release - Works With: wp 2.9.1.
