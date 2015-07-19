=== blogroll2email ===
Contributors: cadeyrn
Donate link:
Tags: blogroll, links, rss, email, reader
Requires at least: 3.0
Tested up to: 4.2.2
Stable tag: 0.2
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Bring back the good ol' web: pull RSS, Atom and microformat feeds from your blogroll links and receive them as email.

== Description ==

The plugin re-activates the long forgotten [blogroll or links](https://en.support.wordpress.com/blogroll/) section of WordPress in order to turn your site to an [rss2email](http://www.aaronsw.com/weblog/001148)-like service.

= How it works: =

* all links that has their RSS link filled in will pull the RSS feed of those sites
* those links that does not yet have RSS will be parsed as [Microformats 2](http://microformats.org/)
  * in case the site has an RSS alternate link, the plugin will fill this information in and use the RSS instead of the microformats parser
* the link names will be updated based on the feed name automatically
* all the parsed entries since the last check will be sent to the link owner's email address as HTML mails with the following additional header:
  * `X-RSS-ID` with the link URL
  * `X-RSS-URL` with the link URL
  * `X-RSS-Feed` with the feed URL
  * `User-Agent` with blogroll2email,
  * these are in place in order for filter script to work
* the revisit time is 30minutes ( not changeable yet )
* the plugin uses WordPress Cron

= Credit =

* thank you for [PHP Mf2 parser](https://github.com/indieweb/php-mf2/) from [Barnaby Walters](https://waterpigs.co.uk/)

== Installation ==

1. Upload contents of `blogroll2email.zip` to the `/wp-content/plugins/` directory
2. Activate the plugin through the `Plugins` menu in WordPress

== Changelog ==

Version numbering logic:

* every A. indicates BIG changes.
* every .B version indicates new features.
* every ..C indicates bugfixes for A.B version.

= 0.2 =
*2015-07-16*

* fine tuning simplepie options


= 0.1 =
*2015-07-16*

* first stable release
