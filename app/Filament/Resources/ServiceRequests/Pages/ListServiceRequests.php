<?php

namespace App\Filament\Resources\ServiceRequests\Pages;

use App\Enums\ServiceRequestStatus as Status;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListServiceRequests extends ListRecords
{
    protected static string $resource = ServiceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * Render the resource tabs in a page-specific table-header wrapper. The
     * wrapper shares Filament's header row with the table toolbar on desktop,
     * while the tabs retain their existing livewireProperty binding.
     */
    public function table(Table $table): Table
    {
        return parent::table($table)->header(
            fn (): HtmlString => new HtmlString(
                '<div class="himo-service-request-tabs">'.Schema::make($this)
                    ->components([$this->getTabsContentComponent()])
                    ->toEmbeddedHtml().'</div>',
            ),
        );
    }

    /**
     * ListRecords normally renders resource tabs before the table. This page
     * owns the tab placement, so its schema contains only the embedded table.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
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
