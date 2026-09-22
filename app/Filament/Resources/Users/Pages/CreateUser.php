<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Phase 8: every user gets "requester" by default, alongside whatever
     * functional role the form assigned. assignRole() is a no-op if the
     * form's role selection already was requester.
     */
    protected function afterCreate(): void
    {
        $this->record->assignRole('requester');
    }
}
