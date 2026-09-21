<?php

namespace Ncpd\SecureShare\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ncpd\SecureShare\Models\SecureShare;
use Ncpd\SecureShare\Services\SecretCipher;

final class CreateSecureShareAction
{
    /**
     * @param  array<int, UploadedFile>  $attachments
     * @param  array{created_by?: ?int, expires_at?: ?\Carbon\CarbonInterface, max_views?: ?int, burn_after_reading?: bool}  $options
     *
     * @throws ValidationException
     */
    public function execute(
        string $passcode,
        ?string $text = null,
        array $attachments = [],
        array $options = [],
    ): SecureShare {
        $minLength = (int) config('secure-share.passcode.min_length', 8);

        $validator = Validator::make(
            [
                'passcode' => $passcode,
                'text' => $text,
                'attachments' => $attachments,
            ],
            [
                'passcode' => ['required', 'string', 'min:'.$minLength],
                'text' => ['nullable', 'string'],
                'attachments' => ['present', 'array'],
                'attachments.*' => ['file'],
            ],
        );

        $validator->after(function ($validator) use ($text, $attachments) {
            if (blank($text) && count($attachments) === 0) {
                $validator->errors()->add(
                    'text',
                    'A secure share must contain text or at least one attachment.'
                );
            }
        });

        $validator->validate();

        /** @var class-string<SecureShare> $model */
        $model = config('secure-share.model');

        /** @var SecureShare $share */
        $share = $model::create([
            'share_token' => bin2hex(random_bytes(32)),
            // Always encrypted — even for attachment-only shares — so the
            // passcode stays verifiable at unlock time (empty body decrypts
            // to an empty string).
            'ciphertext' => SecretCipher::encrypt($text ?? '', $passcode),
            'has_attachments' => count($attachments) > 0,
            'max_views' => $options['max_views'] ?? null,
            'burn_after_reading' => (bool) ($options['burn_after_reading'] ?? false),
            'expires_at' => $options['expires_at'] ?? null,
            'created_by' => $options['created_by'] ?? null,
        ]);

        foreach ($attachments as $file) {
            $adder = $share
                ->addMediaFromString(SecretCipher::encrypt($file->getContent(), $passcode))
                ->usingFileName(bin2hex(random_bytes(16)).'.bin')
                ->withCustomProperties([
                    'original_name' => $file->getClientOriginalName(),
                    'original_mime' => $file->getClientMimeType(),
                    'original_size' => $file->getSize(),
                    'encrypted' => true,
                ]);

            // A null config disk means "use Media Library's own default disk",
            // which is only expressible by omitting the argument entirely.
            if (($disk = config('secure-share.disk')) === null) {
                $adder->toMediaCollection('secure-attachments');
            } else {
                $adder->toMediaCollection('secure-attachments', $disk);
            }
        }

        return $share;
    }
}
