<?php

namespace Jodeveloper\SecureShare\Tests\Fixtures;

use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Jodeveloper\SecureShare\Filament\Actions\SecureShareAction;
use Jodeveloper\SecureShare\Filament\Schemas\SecureShareForm;

class SecureShareActionPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    public function secureShare(): SecureShareAction
    {
        return SecureShareAction::make();
    }

    public function deniedSecureShare(): SecureShareAction
    {
        return SecureShareAction::make('deniedSecureShare')
            ->authorize(fn (): bool => false);
    }

    public function selectorSecureShare(): SecureShareAction
    {
        return SecureShareAction::make('selectorSecureShare')
            ->attachments(fn (array $data): array => [
                UploadedFile::fake()->createWithContent(
                    'selector.txt',
                    (string) $data['source_text'],
                ),
            ])
            ->schema([
                TextInput::make('source_text')->required(),
                ...SecureShareForm::components(withContent: false),
            ]);
    }
}
