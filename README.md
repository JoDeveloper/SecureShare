# ncpd/secure-share

Passcode-encrypted secret + attachment sharing for Laravel + Filament.

Any host app installs the package, runs the migration, registers the Filament
plugin, and gets a working share-via-link feature — reusing whatever Spatie
Media Library disk (OSS, S3, local) the host already has configured.

## Requirements

- PHP 8.3+ with `ext-sodium`
- Laravel 11.28+ / 12
- Filament v5 (panel)
- `spatie/laravel-medialibrary` v11, already installed **and migrated** in the
  host app (this package never touches the `media` table's schema — it only
  reads and writes rows in the `secure-attachments` collection)

## Installation

```bash
composer require ncpd/secure-share

php artisan vendor:publish --tag="secure-share-config"
php artisan vendor:publish --tag="secure-share-migrations"
php artisan migrate
```

Register the plugin once in your panel provider:

```php
use Ncpd\SecureShare\SecureSharePlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...your existing configuration
        ->plugin(SecureSharePlugin::make());
}
```

That's it. Your panel gets a **Secure shares** page, and the public
`/secure-share/{token}` routes go live.

## How it works

1. An authenticated panel user writes the secret text (and/or attaches files)
   plus a passcode on the **Create secure share** page.
2. The body and every attachment are encrypted with a key derived from the
   passcode via `sodium_crypto_pwhash` (Argon2id) + `sodium_crypto_secretbox`.
   The passcode itself is never stored anywhere.
3. Attachment ciphertext is stored through Media Library in the
   `secure-attachments` collection on `config('secure-share.disk')`
   (`null` = Media Library's default disk, e.g. your OSS disk).
4. The recipient opens `/secure-share/{token}`, enters the passcode, and gets
   the decrypted text plus download links. Downloads stream decrypted bytes
   and never write plaintext to disk.
5. Optional limits: expiry (30 min – 30 days / never), max views, and
   burn-after-reading (the share — row and files — is deleted by a queued job
   immediately after the first successful unlock).

## Scheduling

The package auto-schedules `secure-share:purge` **hourly**. The command:

- marks `Active` shares past `expires_at` as `Expired`, and past `max_views`
  as `Exhausted`,
- deletes `Burned` shares (belt-and-braces for the queued delete job),
- deletes `Expired`/`Exhausted` shares — and their media files — once they are
  older than `purge.retention_days` (default 7 days).

If you prefer your own schedule, set `'purge' => ['schedule' => false]` and
register it yourself, e.g. `Schedule::command('secure-share:purge')->hourly();`.
Either way your scheduler must be running (`schedule:work` / cron entry).

## Configuration

Published at `config/secure-share.php`:

| Key | Default | Meaning |
|---|---|---|
| `model` | `SecureShare::class` | Swap/extend the Eloquent model |
| `disk` | `null` | Attachment disk; `null` = Media Library default |
| `route.prefix` | `secure-share` | Public URL prefix |
| `route.middleware` | `['web']` | Route middleware (includes CSRF) |
| `passcode.min_length` | `8` | Minimum passcode length |
| `lockout.max_attempts` | `5` | Wrong passcodes before lockout |
| `lockout.lockout_minutes` | `15` | Lockout duration |
| `throttle` | `'6,1'` | `requests,minutes` on unlock + download, keyed by token **and** IP |
| `unlock_ttl` | `600` | Seconds a successful unlock keeps downloads working |
| `max_upload_kb` | `10240` | Max size per attachment (Filament upload cap) |
| `purge.schedule` | `true` | Auto-schedule the hourly purge |
| `purge.retention_days` | `7` | How long expired/exhausted shares linger before deletion |

## Views & translations

The public page and the Filament page are namespaced (`secure-share::`) and
publishable — override styling per host app:

```bash
php artisan vendor:publish --tag="secure-share-views"
php artisan vendor:publish --tag="secure-share-translations"
```

Translations ship in English and Arabic; the public page mirrors correctly in
RTL locales (logical CSS properties) and degrades gracefully without
JavaScript. Add locales by publishing translations.

## Security model

**What is protected**

- Secret text and attachment *contents* are encrypted with the passcode
  before they touch the database or object storage. A full dump of DB + files
  yields only ciphertext.
- The passcode is never written to the database, logs, or cache.
- Every unlock failure mode — unknown token, wrong passcode, expired,
  exhausted, locked — returns the **identical** generic message, so responses
  are not an oracle.
- Lockout (5 attempts → 15 minutes) plus per-token-and-IP rate limiting on
  the unlock and download routes.
- Downloads are isolated per share: a media UUID from share B cannot be
  fetched through share A's URL (404).
- Attachment downloads force `Content-Disposition: attachment` +
  `X-Content-Type-Options: nosniff`; the reveal page disables indexing and
  caching (`noindex, nofollow`, `Cache-Control: no-store`).

**Known trade-offs (read before deploying)**

1. **Transient passcode in the server-side session.** The spec requires a
   separate streaming download route *and* forbids persisting the passcode —
   two requirements that cannot both hold in PHP's request model. After a
   successful unlock, the passcode is kept in the server-side session for
   `unlock_ttl` seconds (default 10 minutes) solely so downloads can decrypt;
   it is forgotten on expiry and never reaches the client. Treat the session
   store with the same care as the database.
2. **Livewire temp-upload staging.** While an admin picks files in the Filament
   form, Livewire stages the plaintext upload on its temporary disk before the
   form is submitted and the bytes are encrypted. This is inherent to Livewire
   uploads; files are removed with the temp cleanup.
3. **Attachment metadata is cleartext.** Original filenames, MIME types, and
   sizes live unencrypted in the `media` table (`custom_properties`) so
   downloads can restore them. If filenames are sensitive in your threat
   model, rename files before attaching.
4. **Whole-file decrypt in memory.** Downloads decrypt the attachment in
   memory (capped by `max_upload_kb`). Very large attachments increase peak
   memory per download.
5. **View counting is not atomic.** Concurrent unlocks of the same share can
   race `view_count`. For typical use this is cosmetic; treat `max_views` as
   approximate under adversarial concurrency.

## Testing

```bash
composer test
```

The suite covers the cipher (roundtrip, tamper, wrong passcode), create and
unlock actions (lockout, expiry, burn, exhaustion), the full HTTP flow
(reveal, download, isolation, TTL, message parity, passcode-never-persisted),
the Filament page, and the purge command.

## License

MIT
