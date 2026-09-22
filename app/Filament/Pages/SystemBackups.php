<?php

namespace App\Filament\Pages;

use App\Models\SystemBackup;
use App\Services\BackupService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SystemBackups extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.system-backups';

    protected static ?string $title = 'System backups';

    protected static ?string $navigationLabel = 'Backups';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('Manage:SystemBackup') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(SystemBackup::query())
            ->columns([
                TextColumn::make('filename')->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'completed' ? 'success' : 'danger'),
                TextColumn::make('size_bytes')->label('Size')->formatStateUsing(fn (int $state) => number_format($state / 1_048_576, 2).' MB'),
                TextColumn::make('creator.name')->label('Created by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('error_message')->label('Error')->limit(60)->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                $this->downloadAction(),
                $this->restoreAction(),
                $this->deleteAction(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [$this->createBackupAction()];
    }

    private function createBackupAction(): Action
    {
        return Action::make('createBackup')->label('Create backup now')->color('primary')
            ->requiresConfirmation()
            ->modalDescription('This runs a full pg_dump of the application database and may take a while for large datasets.')
            ->action(function (): void {
                try {
                    app(BackupService::class)->create(auth()->user());
                    Notification::make()->title('Backup created')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Backup failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function downloadAction(): Action
    {
        return Action::make('download')->label('Download')
            ->visible(fn (SystemBackup $record) => $record->status === 'completed')
            ->action(fn (SystemBackup $record) => response()->download(app(BackupService::class)->downloadPath($record), $record->filename));
    }

    private function restoreAction(): Action
    {
        return Action::make('restore')->label('Restore')->color('danger')
            ->icon('heroicon-o-exclamation-triangle')
            ->visible(fn (SystemBackup $record) => $record->status === 'completed')
            ->schema([
                TextInput::make('confirmFilename')
                    ->label('Type the exact backup filename to confirm this irreversible restore')
                    ->required(),
            ])
            ->requiresConfirmation()
            ->modalDescription('This replaces every record in the database with the contents of this backup. The application is taken offline for the duration of the restore. This cannot be undone.')
            ->action(function (SystemBackup $record, array $data): void {
                if ($data['confirmFilename'] !== $record->filename) {
                    throw ValidationException::withMessages(['confirmFilename' => 'The typed filename does not match. Restore cancelled.']);
                }

                try {
                    app(BackupService::class)->restore($record, auth()->user());
                    Notification::make()->title('Restore completed')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Restore failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('deleteFile')->label('Delete backup file')->color('gray')
            ->requiresConfirmation()
            ->action(fn (SystemBackup $record) => app(BackupService::class)->delete($record));
    }
}
