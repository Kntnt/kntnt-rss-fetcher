# Kntnt RSS Query Loop

WordPress plugin that fetches and displays content from multiple RSS feeds, with flexible configuration.

* **Contributors:** TBarregren
* **Tags:** rss
* **Requires at least:** 6.7
* **Tested up to:** 6.7
* **Requires PHP:** 8.3
* **Stable tag:** 1.0.0
* **License:** GPL-3.0-or-later
* **License URI:** http://www.gnu.org/licenses/gpl-3.0.txt

## Description

This plugin creates a limited number of posts of type RSS Item by importing items from an RSS or Atom feed. The post title, excerpt, content, featured image and publication date are taken from the feed item. You can have one or more feeds. The plugin fetches new items at a time interval you specify for each feed. For each feed, there is a limit that you set for how many posts it should contain. The plugin automatically deletes the oldest posts that do not fit within this limit.

The point of importing feed items into posts is that they can all be displayed in the usual way in WordPress, e.g. with the block editor's query loop block.

## Installation

1. Install and activate [ACF Pro](https://www.advancedcustomfields.com/pro/)
2. [Download the plugin zip archive.](https://github.com/Kntnt/kntnt-rss-fetcher/releases/latest/download/kntnt-rss-fetcher.zip)
3. Extract the `acf.json` file from the zip archive.
4. Go to WordPress admin panel → ACF → Tools.
5. In the Import panel, select the extracted `acf.json` file and click "Import JSON"
6. Go to WordPress admin panel → Plugins → Add New.
7. Click "Upload Plugin" and select the downloaded zip archive.
8. Activate the plugin.

## Configuration

1. Go to WordPress admin panel → Settings → RSS Feeds.
2. Fill in the form:
    - **URL:** URL from which the feed should be fetched
    - **Tag:** None, one or more tags with which posts of this type should be marked
    - **Author:** Select yourself or a specially created user as the author of RSS items. This is needed internally in WordPress, but will not be displayed externally.
    - **Max items:** Maximum number of items to store.
    - **Time between fetches:** The minimum number of minutes that must elapse between two downloads of the RSS feed.
3. Click Add Feed to add another feed. Repeat as many times as you need.
4. Don't forget to click the "Update" button.

## Frequently Asked Questions

### How can I get help?

If you have questions about the plugin and cannot find an answer here, start by looking at issues and pull requests. If you still cannot find the answer, feel free to ask in the plugin's issue tracker on GitHub.

### How can I report a bug?

If you have found a potential bug, please report it on the plugin's issue tracker on GitHub.

### How can I contribute?

Contributions to the code or documentation are much appreciated.

If you are familiar with Git, please do a pull request.

If you are not familiar with Git, please create a new ticket on the plugin's issue tracker on GitHub.
