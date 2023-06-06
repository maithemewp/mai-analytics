# Mai Analytics
Track first-party analytics with Matomo.

## Getting Started
Custom Content Areas in Mai Theme v2 are automatically tracked. If you want to track specific blocks on any post/page, you can use the Mai Analytics Tracker wrapper block to add a content name, and inner actionable elements (links, buttons, etc.) will be tracked as well.

## Constants
The following constants can be overridden in `wp-config.php`:

```
MAI_ANALYTICS
```
`bool`: Must be true for Mai Analytics to be used.

```
MAI_ANALYTICS_ADMIN
```
`bool`: Must be true for Mai Analytics to track back end data.

```
MAI_ANALYTICS_SITE_ID
```
`integer`: The site ID.

```
MAI_ANALYTICS_URL
```
`string`: The authentication url. Defaults to `https://analytics.bizbudding.com`.

```
MAI_ANALYTICS_TOKEN
```
`string`: The Matomo analytics token.

```
MAI_ANALYTICS_DEBUG
```
`bool`: Whether Mai Analytics should log via the console and Spatie Ray, if available.

## Shortcode

```
[mai_views]
```

By default it will show all views within the given time in the settings. You can show trending views via the `type` attribute.

```
[mai_views type="trending"]
```

If using with Mai Theme v2 it will default to a heart icon, but you can use any icon available in Mai Icons via the `icon`, `style`, etc. attributes.

```
[mai_views icon="star" icon_margin_right="6px"]
```

All attributes and their defaults:

```
'type'               => '',      // Empty for all, and 'trending' to view trending views.
'min'                => 20,      // Minimum number of views before displaying.
'format'             => 'short', // Use short format (2k+) or show full number (2,143). Currently accepts 'short', '', or a falsey value.
'style'              => 'display:inline-flex;align-items:center;', // Inline styles for the wrapper element.
'icon'               => 'heart',
'icon_style'         => 'solid',
'icon_size'          => '0.85em',
'icon_margin_top'    => '0',
'icon_margin_right'  => '0.25em',
'icon_margin_bottom' => '0',
'icon_margin_left'   => '0',
```