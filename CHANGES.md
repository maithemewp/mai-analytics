# Changelog

## 0.4.7 (1/22/24)
* Fixed: Parenthesis around expression.

## 0.4.6 (1/19/24)
* Fixed: Encoded special characters were displayed on the front end in some configurations.

## 0.4.5 (1/18/24)
* Fixed: Sanitize name field.
* Fixed: Remove unecessary encoding in DOMDocument.

## 0.4.4 (10/02/23)
* Changed: Only check connection on settings page if tracking is enabled.

## 0.4.3 (10/02/23)
* Changed: Version number is now displayed next to plugin name on the settings page.
* Changed: Now displays error code when checking Matomo connection on the settings page.

## 0.4.2 (9/29/23)
* Fixed: Remove unnecessary `urlencode()` from API call.

## 0.4.1 (9/29/23)
* Changed: No longer tracking logged in users that are Contributors or above.

## 0.4.0 (9/29/23)
* Added: Trending post views are saved to mai_trending post/term meta keys for use in custom queries or with Mai Post Grid and Mai Term Grid blocks.
* Added: New `[mai_views]` shortcode to display view counts on posts and terms, with an optional icon.
* Added: Content Length support for Mai Archive Pages.
* Changed: Removed PHP tracker.

## 0.3.0 (5/11/23)
* Added: Mai Analytics Tracker wrapper block.
* Changed: No longer tracks nested content names. The outer most container is used as the tracked content name. Content pieces and triggers remain the same.

## 0.2.4 (5/5/23)
* Fixed: Fix invalid markup in some scenarios.

## 0.2.3 (5/2/23)
* Fixed: Additional `<html><body>` tags were added to Mai Custom Content Areas in some configurations.

## 0.2.2 (4/26/23)
* Added: Plugin action link to settings page from main plugins list.

## 0.2.1 (4/25/23)
* Fixed: Error if a post is not formatted correctly and does not have a proper date stored in the database.

## 0.2.0 (3/21/23)
* Added: New custom dimension data tracked, for content age, type, length, and post category.

## 0.1.0
Initial release.
