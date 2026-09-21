# ncpd/secure-share Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the installable Laravel/Filament package from the provided spec, in the empty repo `/Users/joe/filaVault` (package root = repo root), TDD-first, ending with a green test suite.

**Architecture:** Passcode-derived Sodium key (pwhash + secretbox) encrypts the secret body and each attachment's bytes; the host's existing Spatie Media Library disk stores attachment ciphertext in the `secure-attachments` collection; a public package route pair (unlock form → revealed view → streamed download guarded by a short-lived session flag) never writes plaintext to disk. Admins create shares from one Filament page registered via a single plugin.

**Tech Stack:** PHP 8.3+ (local: 8.4.25 w/ ext-sodium ✓), Laravel 11.28+/12, Filament v5 (Livewire 4), spatie/laravel-medialibrary ^11, spatie/laravel-package-tools, Orchestra Testbench 9/10 + PHPUnit 11.

## Global Constraints (apply to every task)

- Namespace `Ncpd\SecureShare` (spec verbatim; vendor `ncpd/secure-share`).
- Passcode never persisted — not in DB, logs, cache, or session.
- Identical generic copy for wrong-passcode vs expired/not-found/locked/exhausted: `secure-share::messages.unavailable` → "This share is unavailable or the passcode is incorrect."
- No plaintext at rest anywhere (Livewire temp upload staging is the documented sole exception).
- Everything config-driven via `config('secure-share.*')`; model resolved via `config('secure-share.model')`, never hardcoded.
- Attachments only via the `secure-attachments` media collection, disk = `config('secure-share.disk')` (null → medialibrary default).
- Frequent git commits; repo initialized in Task 1 (cwd is not yet a git repo).

## Locked Interfaces

- `SecretCipher::encrypt(string $plaintext, string $passcode): string` / `decrypt(string $encoded, string $passcode): string` — spec code verbatim + min-length guard (`SALTBYTES + NONCEBYTES + MACBYTES`) before parsing.
- `CreateSecureShareAction::execute(string $passcode, ?string $text, array $attachments, array $options = []): SecureShare` — `$options` keys: `created_by` (?int), `expires_at` (?CarbonInterface), `max_views` (?int), `burn_after_reading` (bool). Token = `bin2hex(random_bytes(32))`. Enforces passcode `min_length` and "text or ≥1 attachment".
- `UnlockSecureShareAction::execute(string $shareToken, string $passcode): array{share: SecureShare, plaintext: string}` — throws `ShareUnlockException` (generic message only) for not-found/inactive/expired/locked/exhausted/wrong-passcode.
- `DeleteSecureShareJob(string $shareToken)` implements ShouldQueue; deletes model (media cascade).
- Controller: `show(string $share_token)`, `unlock(Request, string $share_token)`, `download(string $share_token, Media $media)`.
- Session flag: `secure-share.unlocked.{share_token}` = unlock timestamp; TTL `config('secure-share.unlock_ttl')` (default 600s, absolute).
- Media custom properties: `original_name`, `original_mime`, `original_size`, `encrypted => true`; stored filename random hex `.bin`, mime `application/octet-stream`.
- Routes (names `secure-share.show|unlock|download`): `GET/POST /{prefix}/{share_token}`, `GET /{prefix}/{share_token}/download/{media:uuid}` — prefix/middleware from `config('secure-share.route')`; `throttle:secure-share` on POST + download.
- Config additions beyond spec: `unlock_ttl=600`, `max_upload_kb=10240`, `purge => [schedule=true, retention_days=7]` (spec keys unchanged: model, disk, route, passcode.min_length, lockout.max_attempts/lockout_minutes, throttle='6,1').

---

### Task 1: Repo scaffold + bootable TestCase

**Files:** composer.json (spec + autoload-dev `Ncpd\SecureShare\Tests\`, scripts.test, prefer-stable), .gitignore (vendor, .DS_Store), phpunit.xml.dist (sqlite `:memory:`, APP_ENV=testing), config/secure-share.php (full, incl. additions above), README stub, src/SecureShareServiceProvider.php (package-tools: name/config/migration/route/views/translations — command & rate-limiter added in Tasks 6/8), tests/TestCase.php (providers: ours + MediaLibraryServiceProvider; env: filesystems test disk, medialibrary `disk_name`), tests smoke test asserting `config('secure-share.passcode.min_length') === 8`.

- [x] git init → write files → `composer validate` → `composer install` → run smoke test → commit "chore: package scaffold".

### Task 2: Enum + migration + model + DecryptionFailedException

**Files:** migration stub (id; share_token string(64) unique; ciphertext longText nullable; has_attachments bool default false; max_views unsignedInteger nullable; view_count unsignedInteger default 0; burn_after_reading bool default false; expires_at nullable; failed_attempts unsignedInteger default 0; locked_until nullable; created_by foreignId nullable constrained nullOnDelete; status string(32) default 'active'; timestamps), src/Enums/SecureShareStatus.php (string-backed: Active/Expired/Exhausted/Burned), src/Models/SecureShare.php (guarded=[], casts incl. `status => SecureShareStatus::class`, `InteractsWithMedia`, `registerMediaCollections` → `addMediaCollection('secure-attachments')`, no conversions, `creator()` belongsTo users model from config), exceptions class.

- [ ] failing test (publish `secure-share-migrations` + `medialibrary-migrations` tags, `artisan migrate`, assert `Schema::hasColumns` incl. media table; assert model `->status` is enum instance) → implement → pass → commit.

### Task 3: SecretCipher (unit, pure TDD)

- [ ] write `tests/Unit/SecretCipherTest.php` (roundtrip incl. unicode + large payload; wrong passcode throws DecryptionFailedException; flipped byte throws; truncated throws) → run, fail → paste spec implementation + length guard → run, pass → commit.

### Task 4: CreateSecureShareAction

- [ ] failing `tests/Feature/SecureShareActionsTest.php` (create with text + `UploadedFile::fake()` attachment → token 64 hex chars unique, ciphertext ≠ plaintext, media in `secure-attachments` with encrypted on-disk bytes ≠ plaintext and custom properties set, has_attachments true; disk config honored; passcode too short throws ValidationException; neither text nor files throws) → implement (model from `config('secure-share.model')`, `addMediaFromString(SecretCipher::encrypt($file->getContent(), $passcode))->usingFileName(bin2hex(random_bytes(16)).'.bin')->withCustomProperties([...])->toMediaCollection('secure-attachments', config('secure-share.disk'))`) → pass → commit.

### Task 5: UnlockSecureShareAction + DeleteSecureShareJob

- [ ] failing tests (correct unlock returns plaintext; wrong passcode: throws generic + `failed_attempts` incremented; Nth failure sets `locked_until` + resets counter; locked share rejects even correct passcode; expired Active share flipped to Expired + rejected; max_views reached → status Exhausted + rejected; burn: dispatches job, sync-queue deletes row + media files) → implement (order: find → status → locked → expires → decrypt-catch → increment/lockout; success: reset counter, `view_count`++, burn → status Burned + `dispatch(new DeleteSecureShareJob($token))`, else exhaustion check; save) → pass → commit.

### Task 6: Routes + controller + limiter + public views + lang + flow tests

**Files:** routes/web.php, controller, `RateLimiter::for('secure-share')` in provider boot (parse `config('secure-share.throttle')` "requests,minutes"; key = `share_token|ip`), resources/views/public/unlock.blade.php (standalone page, logical CSS properties = RTL-ready, Alpine via CDN defer but form works with JS off: states = passcode form / revealed (plaintext `<pre>` + copy + attachment list linking `secure-share.download` + meta) / generic error; `@csrf`), lang en + ar.

- [ ] failing `tests/Feature/SecureShareFlowTest.php` (full flow GET form → POST reveal → download returns exact original bytes + `Content-Disposition` original filename; wrong-passcode flash message === unknown-token GET generic copy; burn-after-reading: reveal once then 404 + files gone; lockout after configured attempts; cross-share isolation — A's download w/ B's media uuid → 404; download without/expired session flag → redirect + generic error; assert DB row + media file + laravel.log never contain the passcode) → implement → pass → commit.

### Task 7: Filament plugin + page

**Files:** SecureSharePlugin (spec verbatim), src/Filament/Pages/CreateSecureShare.php (HasForms; form: passcode `password()->revealable()->minValue(config)->same('passcode_confirmation')` + confirmation, text Textarea nullable, `FileUpload::make('attachments')->multiple()->storeFiles(false)->maxSize(config max_upload_kb)`, burn Toggle, max_views numeric nullable, expiry Select 30m/1h/24h(default)/7d/30d/never → Carbon; `create()` validates text-or-files, calls the action with `auth()->id()`, stores created URL for result panel; Notification success), create-secure-share.blade.php (form + created-result section: URL + copy button + "share the passcode via a separate channel" warning), test panel fixture in TestCase (`AdminPanelProvider` registering the plugin, base path `/admin`).

- [ ] failing `tests/Feature/FilamentPageTest.php` (authed `Livewire::test(CreateSecureShare::class)->fillForm(...)->callAction('create')` → share exists in DB; unauthenticated GET redirects to login; render shows form fields) → implement → pass → commit.

### Task 8: Purge command + schedule + README + full verification

**Files:** PurgeExpiredSharesCommand (`secure-share:purge`: Active+expired_at<now → Expired; Active+view_count≥max_views → Exhausted; delete Burned now; delete Expired/Exhausted older than `purge.retention_days` + their media; output counts), `->hasCommand()` + `Schedule::command('secure-share:purge')->hourly()` gated on `purge.schedule` in provider, README (install: composer → publish config+migrations → migrate → `->plugin(SecureSharePlugin::make())` in panel; scheduling note if disabled; config reference; security model incl. known tradeoffs: Livewire temp-upload staging and cleartext attachment metadata (original filenames) in media table per spec).

- [ ] failing PurgeCommandTest (artisan exits 0, statuses flipped, retention deletion removes media files) → implement → pass → **full gate:** `composer validate` + `vendor/bin/phpunit` all green → commit "feat: complete secure-share package".

## Self-Review Notes

- Spec coverage: every spec section maps to a task (plugin→7, routes→6, checklist items → Tasks 5/6/8: identical errors ✓, rate limit by IP+token ✓, CSRF ✓ (web middleware + @csrf), enum status ✓, purge ✓, no plaintext disk ✓ (streamed decrypt), passcode never persisted ✓ (tested)).
- Two deliberate spec deviations, both flagged in README: throttle config stays the spec's `'6,1'` string (parsed); added `unlock_ttl`/`max_upload_kb`/`purge` config keys the spec's behavior requires but didn't name.
- Tests requiring network only in Task 1 (`composer install`); sodium + PHP 8.4 verified present locally.
