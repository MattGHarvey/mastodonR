# MastodonR WordPress Plugin

MastodonR sends WordPress posts to a Mastodon account when posts are published. It uses the first image in the post content, falling back to the featured image.

## Features

- Automatically posts when a post transitions from non-published to published.
- Adds a **Send to Mastodon** button in the post editor sidebar.
- Registers a Mastodon application and connects through OAuth 2.
- Uploads the image with the post text as alt text.
- Uses the post title and stripped post content as the status text.
- Adds hashtags from configured defaults, WordPress post tags, and available EXIF metadata.
- Keeps hashtags whole when applying Mastodon's 500-character status limit; hyphens in tags become underscores.
- Supports public, unlisted, followers-only, and direct visibility.

## Install

1. Copy the `mastodonr` folder into `wp-content/plugins/`.
2. Activate **MastodonR** in WordPress.
3. Go to **Settings -> MastodonR**.
4. Enter the base URL for your Mastodon instance, such as `https://mastodon.social`, and save.
5. Click **Connect Mastodon Account** and authorize the plugin.

The WordPress site must be reachable by the browser during the OAuth callback. WordPress HTTP API support and PHP cURL are required for media uploads.

## Usage

For a manual upload, edit a post and click **Send to Mastodon** in the **Mastodon** metabox. For automatic posting, publish a post or change it from draft to published. The plugin waits briefly before checking the post status and sending it.