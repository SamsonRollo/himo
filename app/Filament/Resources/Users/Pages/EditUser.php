<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [UserResource::toggleStatusAction()];
    }

    /**
     * Phase 8: re-assigning the functional role here uses syncRoles()
     * internally (a single-role Select), which would otherwise drop
     * "requester" on every edit. Restore it afterward.
     */
    protected function afterSave(): void
    {
        $this->record->assignRole('requester');
    }
}
