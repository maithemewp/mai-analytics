# Changelog

## 0.4.0 (TBD)
* Added: Trending post views are saved to mai_trending post/term meta keys for use in custom queries or with Mai Post Grid (and soon to be Mai Term Grid) blocks.
* Added: New `[mai_views]` shortcode to display view counts, with an optional icon.
* Changed: Updated PHP tracker version, though mostly unused still.

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
