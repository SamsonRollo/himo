<?php

namespace App\Filament\Pages;

use App\Enums\ServiceRequestPriority as Priority;
use App\Enums\ServiceRequestStatus as Status;
use App\Filament\Resources\ServiceRequests\ServiceRequestResource;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ServiceAccomplishmentReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.service-accomplishment-report';

    protected static ?string $title = 'Service accomplishment report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports & Monitoring';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:ServiceAccomplishmentReport') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table->query(ServiceRequest::query()->visibleTo(auth()->user()))
            ->columns(array_map(
                fn (TextColumn $column): TextColumn => $column->toggleable(),
                [...ServiceRequestResource::requestColumns(), TextColumn::make('completion_note')->wrap()],
            ))
            ->filters($this->reportFilters())
            ->defaultSort('created_at', 'desc');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label('Export CSV')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * Multi-select filter per categorical column heading. Options come only
     * from requests visible to the signed-in user, so no filter reveals
     * names or locations outside their scope. A Requester only ever sees
     * their own requests and Service Staff their own assignments, so the
     * filter that would only ever list themselves is left out for them.
     *
     * @return list<SelectFilter>
     */
    private function reportFilters(): array
    {
        $user = auth()->user();
        $seesAllRoles = $user?->hasRole(['service_supervisor', config('filament-shield.super_admin.name')]) ?? false;
        $visible = fn (): Builder => ServiceRequest::query()->visibleTo($user);

        return array_values(array_filter([
            SelectFilter::make('service_category_id')->label('Category')->multiple()->searchable()->preload()
                ->options(fn () => ServiceCategory::withTrashed()->whereIn('id', $visible()->select('service_category_id'))
                    ->orderBy('name')->pluck('name', 'id')),
            SelectFilter::make('location')->multiple()->searchable()->preload()
                ->options(fn () => $visible()->distinct()->orderBy('location')->pluck('location', 'location')),
            SelectFilter::make('priority')->multiple()->options(Priority::options()),
            SelectFilter::make('status')->multiple()->options(Status::options()),
            ($seesAllRoles || $user?->hasRole('service_staff'))
                ? SelectFilter::make('created_by')->label('Requester')->multiple()->searchable()->preload()
                    ->options(fn () => User::whereIn('id', $visible()->select('created_by'))->orderBy('name')->pluck('name', 'id'))
                : null,
            ($seesAllRoles || ! $user?->hasRole('service_staff'))
                ? SelectFilter::make('assigned_to')->label('Assigned staff')->multiple()->searchable()->preload()
                    ->options(fn () => User::whereIn('id', $visible()->whereNotNull('assigned_to')->select('assigned_to'))->orderBy('name')->pluck('name', 'id'))
                : null,
        ]));
    }

    /**
     * Exports exactly what the table currently shows: the same scoped,
     * filtered, searched, and sorted rows (all pages), limited to the
     * columns not toggled off, with each value rendered as displayed.
     */
    private function exportCsv(): StreamedResponse
    {
        $columns = $this->getTable()->getVisibleColumns();
        $records = $this->getFilteredSortedTableQuery()->get();

        return response()->streamDownload(function () use ($columns, $records): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_map(fn (TextColumn $column): string => strip_tags((string) $column->getLabel()), array_values($columns)), escape: '');

            foreach ($records as $record) {
                fputcsv($handle, array_map(fn (TextColumn $column): string => $this->csvValue($record, $column), array_values($columns)), escape: '');
            }

            fclose($handle);
        }, 'service-accomplishment-report-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvValue(ServiceRequest $record, TextColumn $column): string
    {
        $value = data_get($record, $column->getName());

        $text = match (true) {
            $value === null || $value === '' => ($placeholder = $column->getPlaceholder()) instanceof Htmlable ? strip_tags($placeholder->toHtml()) : (string) $placeholder,
            $value instanceof BackedEnum && method_exists($value, 'label') => $value->label(),
            $value instanceof CarbonInterface => $value->toDateTimeString(),
            default => (string) $value,
        };

        // Neutralise spreadsheet formula injection from user-entered text.
        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
