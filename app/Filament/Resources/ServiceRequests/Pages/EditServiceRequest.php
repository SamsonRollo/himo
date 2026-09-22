<?php

namespace App\Filament\Resources\ServiceRequests\Pages;

use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Services\ServiceRequestWorkflow;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditServiceRequest extends EditRecord
{
    protected static string $resource = ServiceRequestResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ServiceRequestWorkflow::class)->updateDetails($record, $data);
    }
}
