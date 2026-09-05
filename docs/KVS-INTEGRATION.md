# KVS 6.4.0 integration checklist

1. In `/admin/include/setup.php`, keep KVS image extensions limited to JPEG, PNG, and WebP. Do not enable GIF or SVG for member avatars.
2. Identify the absolute member-avatar root and confirm that profile, comments, messages, and member lists all read from it.
3. Deploy this service outside that public avatar directory.
4. Configure the web server so only `public/` is executable and only the KVS host can reach `moderate.php`.
5. Add the synchronous call from `integrations/kvs/submit_avatar.php` while the upload is still in PHP's private temporary file, before KVS publishes it.
6. Render the returned `warning` in the `member_profile_edit` response. Do not reveal category scores to members.
7. Run `bin/scan.php --baseline` once, then schedule `bin/scan.php` every minute for missed-hook reconciliation and retries.
8. Clear the specific KVS member/profile block caches after replacement if the theme caches avatar URLs or image contents.

## Wrapper routing check

Inspect the rendered profile form action and the web-server rewrite that handles it before installing the prepend hook. The tested KVS 6.4.0 theme submits `/edit-profile/` asynchronously and rewrites that request to `index.php`, so the hook must be required at the start of the unencoded `index.php` wrapper. Adding it only to `member_profile_my.php` protects page-renderer posts but does not intercept this AJAX upload route.

## Acceptance test

- A valid ordinary JPEG becomes a metadata-free square image and remains visible.
- A PNG and WebP take the same path.
- A renamed SVG, malformed JPEG, oversized file, oversized dimension, GIF, and animated image never become public.
- A flagged image is retained only in private quarantine and the public path contains the policy-violation avatar.
- An OpenAI timeout produces the under-review avatar and is retried by the scanner.
- Replaying the same signed hook request is rejected.
- `../` traversal, absolute paths, and symbolic links are rejected.
- The new avatar appears consistently in the member profile, comments, messages, and header after cache invalidation.

## KVS support question

Ask KVS Support:

> On our KVS 6.4.0 build, what supported hook or custom-block extension point receives the avatar in PHP's temporary upload file before `member_profile_edit` publishes it? We need to synchronously call a local PHP moderation service, publish its sanitized/replacement file at KVS's canonical avatar path, and avoid modifying encoded core files. Please also identify the canonical avatar-path generation routine and any member/profile cache invalidation function we should call after replacement.
