<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('secure-share::messages.title') }}</title>
    {{-- Logical CSS properties throughout, so RTL locales mirror correctly --}}
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f4f5f7; color: #1a1c20; padding: 1.25rem;
        }
        .card {
            width: min(100%, 34rem); background: #fff; border-radius: .75rem;
            padding: 2rem; box-shadow: 0 10px 30px rgb(0 0 0 / 8%);
            border: 1px solid rgb(0 0 0 / 6%);
        }
        header { text-align: center; margin-block-end: 1.5rem; }
        .lock { font-size: 2.25rem; }
        h1 { font-size: 1.25rem; margin: .5rem 0 0; }
        h2 { font-size: .85rem; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin: 1.5rem 0 .5rem; }
        label { display: block; font-size: .9rem; font-weight: 600; margin-block-end: .375rem; }
        input[type="password"] {
            width: 100%; padding: .7rem .9rem; border-radius: .5rem;
            border: 1px solid #d1d5db; font-size: 1rem;
        }
        input[type="password"]:focus { outline: 2px solid #2563eb; outline-offset: 1px; }
        button {
            margin-block-start: 1rem; width: 100%; padding: .7rem 1rem;
            background: #1a1c20; color: #fff; border: 0; border-radius: .5rem;
            font-size: 1rem; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #33373d; }
        button.ghost { background: transparent; color: #1a1c20; border: 1px solid #d1d5db; width: auto; padding-inline: 1rem; }
        button.ghost:hover { background: #f4f5f7; }
        .error {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            border-radius: .5rem; padding: .75rem 1rem; font-size: .9rem;
        }
        pre.secret-box {
            background: #111318; color: #e5e7eb; padding: 1rem; border-radius: .5rem;
            white-space: pre-wrap; word-break: break-all; font-size: .9rem; margin: 0;
            max-height: 22rem; overflow: auto;
        }
        ul.files { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
        ul.files li {
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            border: 1px solid #e5e7eb; border-radius: .5rem; padding: .65rem .9rem;
        }
        ul.files a { color: #2563eb; font-weight: 600; text-decoration: none; }
        ul.files a:hover { text-decoration: underline; }
        .meta { color: #6b7280; font-size: .85rem; margin-block-start: 1.5rem; text-align: center; }
        .meta p { margin: .25rem 0; }
        .warn {
            background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
            border-radius: .5rem; padding: .65rem 1rem; font-size: .85rem; font-weight: 600;
        }
        footer.site { margin-block-start: 1.25rem; text-align: center; font-size: .75rem; color: #9ca3af; }
        @media (prefers-color-scheme: dark) {
            body { background: #111318; }
            .card { background: #1b1e24; border-color: rgb(255 255 255 / 8%); color: #e5e7eb; }
            input[type="password"] { background: #111318; color: #e5e7eb; border-color: #374151; }
            button { background: #e5e7eb; color: #111318; }
            button:hover { background: #d1d5db; }
            button.ghost { color: #e5e7eb; border-color: #374151; }
            button.ghost:hover { background: #262a31; }
            .error { background: rgb(127 29 29 / 15%); border-color: rgb(185 28 28 / 40%); color: #fca5a5; }
            ul.files li { border-color: #374151; }
            .warn { background: rgb(146 64 14 / 12%); border-color: rgb(217 119 6 / 35%); color: #fcd34d; }
        }
    </style>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
<main class="card" x-data="secureShare">
    <header>
        <div class="lock" aria-hidden="true">🔐</div>
        <h1>{{ __('secure-share::messages.heading') }}</h1>
    </header>

    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif

    @if ($revealed)
        @if ($plaintext !== null && $plaintext !== '')
            <section aria-label="{{ __('secure-share::messages.secret') }}">
                <h2>{{ __('secure-share::messages.secret') }}</h2>
                <pre class="secret-box" x-ref="secret">{{ $plaintext }}</pre>
                <button type="button" class="ghost" @click="copy($refs.secret.textContent)"
                        :disabled="copied" style="margin-block-start:.5rem">
                    <span x-text="copied ? '{{ __('secure-share::messages.copied') }}' : '{{ __('secure-share::messages.copy') }}'">{{ __('secure-share::messages.copy') }}</span>
                </button>
            </section>
        @endif

        @if ($attachments->isNotEmpty())
            <section aria-label="{{ __('secure-share::messages.attachments') }}">
                <h2>{{ __('secure-share::messages.attachments') }}</h2>
                <ul class="files">
                    @foreach ($attachments as $attachment)
                        <li>
                            <a href="{{ route('secure-share.download', ['share_token' => $shareToken, 'media' => $attachment->uuid]) }}"
                               download>{{ $attachment->custom_properties['original_name'] ?? 'attachment' }}</a>
                            <span class="meta">
                                {{ \Illuminate\Support\Number::fileSize($attachment->custom_properties['original_size'] ?? $attachment->size) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($share)
            <div class="meta">
                @if ($share->burn_after_reading)
                    <p class="warn">{{ __('secure-share::messages.burn_notice') }}</p>
                @elseif ($share->max_views)
                    <p>{{ trans_choice('secure-share::messages.views_left', max(0, $share->max_views - $share->view_count)) }}</p>
                @endif
                @if ($share->expires_at)
                    <p>{{ __('secure-share::messages.expires_at') }}: {{ $share->expires_at->toDayDateTimeString() }}</p>
                @endif
            </div>
        @endif
    @elseif ($shareToken)
        <form method="POST"
              action="{{ route('secure-share.unlock', ['share_token' => $shareToken]) }}">
            @csrf
            <label for="passcode">{{ __('secure-share::messages.passcode') }}</label>
            <input id="passcode" type="password" name="passcode" required autocomplete="off"
                   @if (! $error) autofocus @endif>
            @error('passcode')
                <p class="error" role="alert" style="margin-block-start:.75rem">{{ $message }}</p>
            @enderror
            <button type="submit">{{ __('secure-share::messages.unlock') }}</button>
        </form>
    @endif
</main>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('secureShare', () => ({
            copied: false,
            async copy(text) {
                if (navigator.clipboard) {
                    await navigator.clipboard.writeText(text);
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2500);
                }
            },
        }));
    });
</script>
</body>
</html>
