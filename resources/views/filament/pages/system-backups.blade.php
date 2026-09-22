<x-filament-panels::page>
    <p>Server-side database backups. Files are stored privately on the application server, never in the browser. Restoring is destructive and takes the application offline for the duration of the operation.</p>
    {{ $this->table }}
</x-filament-panels::page>
