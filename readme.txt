=== RPS Sitemap Generator ===
Contributors: redpixelstudios
Donate link: http://redpixel.com/donate
Tags: rps, red, pixel, studios, xml, sitemap, generator, google, multisite, support, awareness, lightweight, simple, easy, clean, settings, options
Requires at least: 3.3.1
Tested up to: 3.3.1
Stable tag: 1.1.1

A lightweight XML sitemap generator with Multisite awareness.

== Description ==
This is a simple, lightweight XML sitemap generator designed with the end-user in mind. It provides a familiar interface for viewing new or updated posts and making last minute changes before updating your sitemap. To top it off the plugin integrates seamlessly with Multisite (each blog in your network has its own unique sitemap). This truly is the bare-bone essentials -- what you need -- how you want it -- your life made simple.

= To update your sitemap =
After installing and activating the plugin, a new menu item will appear under Tools labeled Sitemap Generator (you must have import access to see it). Visit the Sitemap Generator tool and click either "Generate Sitemap" if you've yet to generate one, or "Update Sitemap" if you have. A fresh sitemap will be generated at the location you specified in your sitemap options (`/sitemap.xml` by default).

= Sitemap Elements Used =
* loc
* lastmod

= Features =
* Option to exclude individual posts or pages from your sitemap.

= Options =
* Change your sitemap's location with a custom path (relative to your WordPress installation directory).

== Installation ==

1. Upload the `rps-sitemap-generator` directory and its containing files to the `/wp-content/plugins/` directory.
1. Activate the plugin through the "Plugins" menu in WordPress.

== Screenshots ==

1. This is what you see when your sitemap is out of date. It even has sorting and paging. Nifty, huh? :)

== Changelog ==

= 1.1.1 =
* Fixed a bug that would cause sitemap generation to fail if your FTP user did not have document root access. As a result, your sitemap location is now relative to your WordPress installation directory as opposed to your document root.
* Props to [Andy](http://redpixel.com/andy-goodaker/) of [Red Pixel Studios](http://redpixel.com/) for catching this fatal oversight.

= 1.1 =
* First official release version.

== Upgrade Notice ==

= 1.1.1 =
It is highly recommended that you update to this version as it fixes a fatal oversight which prevents sitemap generation if your FTP user does not have document root access.