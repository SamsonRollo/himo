<?php

namespace App\Filament\Resources\ServiceRequests\Pages;

use App\Enums\ServiceRequestStatus as Status;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListServiceRequests extends ListRecords
{
    protected static string $resource = ServiceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * "Active" is everything not yet Completed within the signed-in user's
     * visible scope; "History" is Completed requests plus, for Service
     * Staff, anything the assignment log shows they once worked but are no
     * longer the current assignee for (see ServiceRequest::scopeVisibleTo).
     */
    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', Status::Completed)),
            'history' => Tab::make('History')
                ->modifyQueryUsing(function (Builder $query) {
                    $user = auth()->user();

                    return $query->where(function (Builder $query) use ($user): void {
                        $query->where('status', Status::Completed);
                        if ($user?->hasRole('service_staff')) {
                            $query->orWhere(fn (Builder $q) => $q->where('assigned_to', '!=', $user->id)->orWhereNull('assigned_to'));
                        }
                    });
                }),
        ];
    }
}
