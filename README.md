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

The service provider is registered through Composer package discovery. Publish
the migrations again when upgrading to a release that adds one, then run
`php artisan migrate` before using the new behavior.

After upgrading from a release with the older unlock-session format, recipients
must unlock shares again. Run the published migration before serving requests.

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
   The raw passcode is never written to the database, logs, or client; a
   successful unlock stores an encrypted copy in the server-side session grant.
3. Attachment ciphertext is stored through Media Library in the
   `secure-attachments` collection on `config('secure-share.disk')`
   (`null` = Media Library's default disk, e.g. your OSS disk).
4. The recipient opens `/secure-share/{token}`, enters the passcode, and gets
   the decrypted text plus download links. Downloads stream decrypted bytes
   and never write plaintext to disk.
5. Optional limits: expiry (30 min – 30 days / never), max views, and
   burn-after-reading. A burned share rejects later unlocks immediately; its
   attachment download grant remains available for the configured short window
   and cleanup runs afterward.

## Scheduling

The package auto-schedules `secure-share:purge` **hourly**. The command:

- marks `Active` shares past `expires_at` as `Expired`, and past `max_views`
  as `Exhausted`,
- retries cleanup for `Burned` shares after the download grant window,
- runs cleanup itself through the model's `erase()` path for eligible
  expired/exhausted ciphertext and encrypted media; queued cleanup is
  supplementary. Soft-deleted metadata remains available for audit and retry.
  `purge.retention_days` (default 7 days) controls cleanup eligibility, not
  metadata retention.

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
| `max_upload_kb` | `10240` | Max size per attachment enforced by the create action and Filament UI |
| `purge.schedule` | `true` | Auto-schedule the hourly purge |
| `purge.retention_days` | `7` | How long expired/exhausted ciphertext waits before cleanup is eligible |

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

## Reusable Filament action

Use the native Filament action anywhere a host resolves its source files. The
attachment resolver is trusted server-side code; it receives validated action
data and the current record. Each descriptor must provide a configured disk,
path, and download name, with an optional MIME type:

```php
use App\Models\Template;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Ncpd\SecureShare\Filament\Actions\SecureShareAction;
use Ncpd\SecureShare\Filament\Schemas\SecureShareForm;

SecureShareAction::make()
    // Define TemplatePolicy::share() to enforce record visibility and sharing rights.
    ->authorize(fn (Template $record): bool => Filament::auth()->user()?->can('share', $record) ?? false)
    ->attachments(fn (array $data, Template $record): array => [[
        'disk' => config('filament.default_filesystem_disk'),
        'path' => $record->templateFiles()->findOrFail($data['version_id'])->file_path,
        'name' => 'template.docx',
        'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]]);
```

When the resolver supplies the content, add host selectors and reuse the
package controls without the text/upload inputs:

```php
->schema([
    Select::make('version_id')->required(),
    ...SecureShareForm::components(withContent: false),
])
```

The action uses the active Filament guard for `created_by`, validates the
passcode and limits, and shows a persistent notification containing the URL.
The descriptor path is read from the configured disk by trusted server-side
code; do not accept paths or URLs from request data.

## Security model

**What is protected**

- Secret text and attachment *contents* are encrypted with the passcode
  before they touch the database or object storage. A full dump of DB + files
  yields only ciphertext.
- The raw passcode is never written to the database, logs, or client. After a
  successful unlock, an encrypted copy is held in the server-side session.
- Every unlock failure mode — unknown token, wrong passcode, expired,
  exhausted, locked — uses the **identical** generic error message. Random,
  high-entropy tokens remain the primary protection against guessing.
- Lockout (5 attempts → 15 minutes) plus per-token-and-IP rate limiting on
  the unlock and download routes.
- Downloads are isolated per share: a media UUID from share B cannot be
  fetched through share A's URL (404).
- Attachment downloads force `Content-Disposition: attachment` +
  `X-Content-Type-Options: nosniff`; the reveal page disables indexing and
  caching (`noindex, nofollow`, `Cache-Control: no-store`).

**Known trade-offs (read before deploying)**

1. **Unlock grant trust boundary.** `Crypt::encryptString()` uses the
   application's `APP_KEY` to wrap the passcode inside a server-side session
   grant. The grant TTL authorizes downloads for `unlock_ttl` seconds (default
   10 minutes), while the encrypted value can remain dormant until the session
   expires or it is cleared. Protect `APP_KEY` and the session store as
   carefully as the database; the raw passcode is never returned to the browser.
2. **Livewire temp-upload staging.** While an admin picks files in the Filament
   form, Livewire stages the plaintext upload on its temporary disk before the
   form is submitted and the bytes are encrypted. This is inherent to Livewire
   uploads; files are removed with the temp cleanup.
3. **Attachment metadata is cleartext.** Original filenames, MIME types, and
   sizes live unencrypted in the `media` table (`custom_properties`) so
   downloads can restore them. If filenames are sensitive in your threat
   model, rename files before attaching.
4. **Creation cap vs download memory.** Creation reads are capped by
   `max_upload_kb` before encryption. Downloads currently decrypt the whole
   attachment in memory, so large permitted files increase peak memory; use a
   dedicated streaming storage service if that becomes material.
5. **Atomic limits.** Unlock limits are serialized per share so max views and
   lockout counters cannot be consumed twice by the same row. Production
   concurrency on every supported database and storage driver still requires
   deployment-environment verification.

6. **Soft-delete retention.** Cleanup erases ciphertext and encrypted media
   through the model's `erase()` path while soft-deleted metadata remains
   available for audit and retry. Configure database metadata retention and
   object-storage lifecycle policies together.

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
