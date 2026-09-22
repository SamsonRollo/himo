<?php

namespace App\Filament\Resources\ServiceRequests\Pages;

use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceRequest extends ViewRecord
{
    protected static string $resource = ServiceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...ServiceRequestResource::workflowActions()];
    }

    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        $this->getRecord()->refresh();
    }
}
