# Nakhlah template secure sharing — integration plan

Status: planned. No changes to Nakhlah are included in the package enhancement.

## Agreed behavior

- A dedicated `template.share` permission is required, together with access to the selected template.
- Documents default to the latest version; the creator may select another active version.
- Images are selected from the template's active `images` collection.
- The recipient opens a public passcode-protected URL. Sharing creates an encrypted snapshot and preserves source files.
- Existing previews, automatic version numbering, template visibility levels, and edit permissions continue to apply.

## Implementation order

1. Install the tested package release using Nakhlah's existing Composer distribution workflow and publish/run its migrations. Set the encrypted attachment disk explicitly. Do not register a standalone creation page unless requested.
2. Add `TemplatePolicy::share`, requiring `template.share` and record access. Register the permission through the application's existing permission workflow; grant it only to selected roles. Action authorization must run on submission as well as control visibility.
3. Add one Arabic `مشاركة آمنة` action to `TemplateResource` table rows and reuse it on `ViewTemplate`. Reuse the package sharing action and form. Include document-version or image selection before passcode, expiry, optional view limit, and burn-after-reading controls.
4. Resolve selected document IDs through `templateFiles()` on the authorized template. Choose the latest active version initially. Resolve selected image IDs through that record's `images` collection; exclude `removed_at` media and soft-deleted rows. Reject missing files and IDs belonging to another template.
5. Supply trusted disk/path/name/MIME descriptors to the package. Documents use `config('filament.default_filesystem_disk')`; images use each media item's actual disk and path relative to its root. Do not read source files through public preview URLs or client-supplied paths.
6. Show the resulting URL with a copy control and the instruction to send the passcode through a separate channel. Handle an empty/missing source as validation failure without creating a share.

## Ownership of changes

| Package filaVault | Host Nakhlah |
|---|---|
| Cipher, creation/unlock/cleanup, public routes | Template source resolution and version/image selection |
| Reusable sharing form/action and translations | `template.share` permission and template policy |
| Share migrations and encrypted attachment storage | Composer installation, disk configuration, resource wiring |

Likely host files: `TemplateResource.php`, `ViewTemplate.php`, `TemplatePolicy.php`, template translations, permission registration, Composer/config, and `TemplateLibraryTest.php` or one focused sharing test.

## Acceptance checks

- A user with `template.share` and record access creates a share; a user missing either requirement cannot mount or submit it.
- Latest document version is the default; choosing another version downloads that version's exact bytes.
- Images include only the selected active files from that template.
- Forged IDs, removed media, soft-deleted versions, empty selections, and missing/oversized source files fail safely.
- Recipient download does not require Nakhlah login; it does require a valid passcode unlock and a live download grant.
- Expiry, view exhaustion, burn download window, and grant expiry behave as documented by the package.
- Original files, preview URLs, version numbering, and existing template tests remain unchanged.
- Database fixtures use transaction rollback; no DELETE-based cleanup.
- Run authenticated Arabic/RTL browser checks and unlock/download in a separate recipient session, including the configured OSS disk.

## Deferred scope

No bulk shares, template-content migration, public plaintext links, Office preview changes, or share-management dashboard.
MySQL/PostgreSQL concurrent unlock behavior and real OSS storage still require integration-environment verification.
