# KVS Avatar Moderator

Fail-closed avatar moderation for Kernel Video Sharing (KVS) 6.4.0. Every accepted JPEG, PNG, or WebP is decoded, metadata-stripped, center-cropped, resized, and re-encoded before it is sent to OpenAI's image moderation endpoint. A flagged upload is quarantined and atomically replaced with the policy-violation avatar.

## What it does

- Allows JPEG, PNG, and WebP based on decoded MIME type, not the filename.
- Rejects SVG, GIF, animated/multi-frame images, malformed files, oversized files, and oversized pixel dimensions.
- Re-encodes with Imagick when available or GD as a fallback, removing EXIF/GPS and embedded metadata.
- Creates a safe square avatar without retaining the original upload on the public path.
- Uses OpenAI `omni-moderation-latest` through `POST /v1/moderations`.
- Replaces policy violations with `assets/avatar-policy-violation.png`.
- Replaces temporary API failures with `assets/avatar-under-review.png` and retains a private retry source.
- Keeps originals and JSONL decision records under a private storage root.
- Supports a synchronous, HMAC-authenticated KVS hook and a cron scanner for reconciliation.

## Requirements

- PHP 8.1+
- cURL and Fileinfo PHP extensions
- Imagick (recommended) or GD with JPEG, PNG, and WebP support
- A writable avatar directory and a private storage directory outside the web root
- `OPENAI_API_KEY`

## Configure

Copy `.env.example` to `.env` on the server and set the real paths. Never commit `.env`, `.env.local`, private SSH keys, or API keys.

Important settings:

| Variable | Purpose |
|---|---|
| `KVS_AVATAR_ROOT` | Absolute root containing KVS member-avatar files |
| `MODERATOR_STORAGE_ROOT` | Private quarantine, state, nonce, and audit storage |
| `OPENAI_API_KEY` | OpenAI project API key |
| `KVS_HOOK_SECRET` | At least 32 random characters for signed hook requests |
| `BLOCKED_CATEGORIES` | Moderation categories that cause replacement |
| `BLOCK_ON_MODEL_FLAGGED` | Reject when OpenAI marks the overall result as flagged |

The default policy rejects sexual, violent, graphic-violent, and self-harm imagery. Because this is an avatar service for an adult site, the safest default is to reject all sexual profile images rather than attempt age estimation.

## Run one avatar

```bash
php bin/moderate-avatar.php --path="relative/path/avatar.jpg" --user-id="123"
```

Exit codes are `0` approved, `2` replaced for a violation/invalid image, `3` temporarily under review, and `1` for a service/configuration error.

## KVS integration

The synchronous pre-publication hook is the enforcement point. Call it while the upload is still in PHP's private temporary file, before KVS moves it into the public avatar directory:

```php
require_once '/var/www/kvs-avatar-moderator/current/integrations/kvs/submit_avatar.php';

$decision = kvs_submit_avatar_for_moderation(
    'http://127.0.0.1/kvs-avatar-moderator/submit.php',
    getenv('KVS_HOOK_SECRET'),
    $_FILES['avatar']['tmp_name'],
    $relativeTargetPath,
    $userId,
);

// Display $decision['warning'] to the member when it is not null.
```

The service publishes either the sanitized approved avatar, the violation avatar, or the under-review avatar at the target path. KVS must then save its normal reference to that target path without moving the original upload.

The exact KVS upload hook name and target-path routine are license/source-build dependent. Do not patch an encoded KVS core file or write directly to KVS database fields. Ask KVS Support for the supported pre-save extension point for your 6.4.0 build, or invoke this helper from a custom `member_profile_edit` block. `integrations/kvs/moderate_avatar.php` is also provided as a post-save compatibility hook, but it has a small exposure window and is not the preferred integration.

For installations whose public `member_profile_my.php` is an unencoded wrapper, it can require `integrations/kvs/profile_prepend.php` before KVS's `process_page.php`. The preprocessor acts on PHP's private upload, forces a generated `.jpg` name, and replaces the temporary file before the encoded KVS block can publish it. Back up the wrapper and verify its exact original contents before installing this integration.

Run the scanner every minute as defense in depth:

```cron
* * * * * cd /var/www/kvs-avatar-moderator/current && /usr/bin/php bin/scan.php >> /var/log/kvs-avatar-moderator-cron.log 2>&1
```

The scanner is not a substitute for the synchronous hook: a scanner-only setup can leave an upload publicly visible until the next run.

Before enabling the recurring scanner on an existing site, record the current avatars without sending them to OpenAI:

```bash
php bin/scan.php --baseline
```

## Web server

Expose only `public/` through PHP-FPM/Apache and restrict it to localhost or the KVS server's private address. `public/moderate.php` additionally requires an HMAC signature, timestamp, and one-time nonce. Do not expose the project root, `.env`, `storage/`, `assets/`, or `integrations/` as public files.

## Tests

```bash
composer validate --strict
composer lint
composer test
```

GitHub Actions runs those checks on PHP 8.1, 8.2, and 8.3. Tests use a fake moderation client and never call OpenAI.

## Deployment

The `Deploy over SFTP` workflow uploads an immutable release over SFTP and switches the `current` symlink over SSH. It uses the protected GitHub environment named `production`.

Run this workflow manually after all production secrets and the KVS integration path have been verified. A normal push runs CI but does not deploy automatically.

Create these `production` environment secrets:

- `OPENAI_API_KEY`
- `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`
- `DEPLOY_PATH`, such as `/var/www/kvs-avatar-moderator`
- `KVS_AVATAR_ROOT`, `MODERATOR_STORAGE_ROOT`, and `KVS_HOOK_SECRET`

The deployment writes `.env` inside the release with mode `0600`; it never uploads the local `.env.local` file.

## Operational notes

- Quarantined originals are sensitive user content. Restrict them to the service account and establish a short retention policy.
- Audit records include the user identifier, relative avatar path, decision, category scores, model, request ID, and SHA-256—not image bytes.
- Moderate profile text separately if avatars can include policy-violating text. The image moderation endpoint is not OCR-policy enforcement.
- OpenAI's current API schema does not list image input as applicable to every category. In particular, do not treat this service as reliable age verification or child-safety adjudication. Rejecting every sexual avatar is the safer rule, with human escalation for uncertain cases.
