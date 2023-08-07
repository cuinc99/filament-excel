<?php

namespace pxlrbt\FilamentExcel\Exports;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Component;
use function Livewire\invade;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings as HasHeadings;
use Maatwebsite\Excel\Concerns\WithMapping as HasMapping;
use pxlrbt\FilamentExcel\Events\ExportFinishedEvent;
use pxlrbt\FilamentExcel\Exports\Concerns\CanModifyQuery;
use pxlrbt\FilamentExcel\Exports\Concerns\CanQueue;
use pxlrbt\FilamentExcel\Exports\Concerns\Except;
use pxlrbt\FilamentExcel\Exports\Concerns\Only;
use pxlrbt\FilamentExcel\Exports\Concerns\WithChunkSize;
use pxlrbt\FilamentExcel\Exports\Concerns\WithColumnFormats;
use pxlrbt\FilamentExcel\Exports\Concerns\WithColumns;
use pxlrbt\FilamentExcel\Exports\Concerns\WithFilename;
use pxlrbt\FilamentExcel\Exports\Concerns\WithHeadings;
use pxlrbt\FilamentExcel\Exports\Concerns\WithMapping;
use pxlrbt\FilamentExcel\Exports\Concerns\WithWidths;
use pxlrbt\FilamentExcel\Exports\Concerns\WithWriterType;
use pxlrbt\FilamentExcel\Interactions\AskForFilename;
use pxlrbt\FilamentExcel\Interactions\AskForWriterType;

class ExcelExport implements HasMapping, HasHeadings, FromQuery, ShouldAutoSize, WithColumnWidths, WithColumnFormatting, WithCustomChunkSize
{
    use Exportable, CanQueue  {
        Exportable::download as downloadExport;
        Exportable::queue as queueExport;
        CanQueue::queue insteadof Exportable;
    }
    use EvaluatesClosures;
    use AskForFilename;
    use AskForWriterType;
    use CanModifyQuery;
    use Except;
    use Only;
    use WithChunkSize;
    use WithColumns;
    use WithFilename;
    use WithHeadings;
    use WithWriterType;
    use WithMapping;
    use WithWidths;
    use WithColumnFormats;

    protected string $name;

    protected ?string $label = null;

    protected ?Component $livewire = null;

    protected ?string $livewireClass = null;

    protected ?Model $livewireOwnerRecord = null;

    protected ?Model $modelInstance = null;

    /**
     * @var \Illuminate\Database\Eloquent\Relations\Relation|\Illuminate\Database\Query\Builder|mixed|null
     */
    protected $query = null;

    protected array $formSchema = [];

    protected ?array $formData;

    protected ?string $model = null;

    protected ?string $modelKeyName;

    protected array $recordIds = [];

    public function __construct($name)
    {
        $this->name = $name;
    }

    public static function make(string $name = 'export'): static
    {
        $static = app(static::class, ['name' => $name]);
        $static->setUp();

        return $static;
    }

    public function setUp()
    {
        //
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    public function getFormSchema(): array
    {
        return $this->formSchema;
    }

    public function getLivewire(): ?Component
    {
        if ($this->livewire) {
            return $this->livewire;
        }

        $this->livewire = app($this->livewireClass);

        if ($this->livewire instanceof RelationManager) {
            $this->livewire->ownerRecord = $this->livewireOwnerRecord;
        }

        $this->livewire->bootedInteractsWithTable();

        return $this->livewire;
    }

    public function getLivewireClass(): ?string
    {
        return $this->livewireClass ??= get_class($this->livewire);
    }

    public function getRecordIds(): array
    {
        return $this->recordIds;
    }

    protected function getModelInstance(): Model
    {
        return $this->modelInstance ??= new ($this->getModelClass());
    }

    protected function getResourceClass(): ?string
    {
        if (isset($this->resource)) {
            return $this->resource;
        }

        $livewire = $this->getLivewire();

        if ($livewire === null || ! method_exists($livewire, 'getResource')) {
            return null;
        }

        return $this->getLivewire()::getResource();
    }

    public function getModelClass(): ?string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        if (($resource = $this->getResourceClass()) !== null) {
            $model = $resource::getModel();
        } elseif (($livewire = $this->getLivewire()) instanceof HasTable) {
            $model = $livewire->getTable()->getModel();
        }

        return $this->model ??= $model;
    }

    public function hydrate($livewire = null, $records = null, $formData = null): static
    {
        $this->livewire = $livewire;
        $this->modelKeyName = $this->getModelInstance()->getQualifiedKeyName();
        $this->recordIds = $records?->pluck($this->getModelInstance()->getKeyName())->toArray() ?? [];

        $this->formData = $formData;

        return $this;
    }

    public function export()
    {
        $this->resolveFilename();
        $this->resolveWriterType();

        if (! $this->isQueued()) {
            return $this->downloadExport($this->getFilename(), $this->getWriterType());
        }

        $this->prepareQueuedExport();

        $filename = Str::uuid().'-'.$this->getFilename();
        $userId = auth()->id();

        $this
            ->queueExport($filename, 'filament-excel', $this->getWriterType())
            ->chain([fn () => ExportFinishedEvent::dispatch($filename, $userId)]);

        Notification::make()
            ->title(__('filament-excel::notifications.queued.title'))
            ->body(__('filament-excel::notifications.queued.body'))
            ->success()
            ->seconds(5)
            ->icon('heroicon-o-arrow-down-tray')
            ->send();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\Relation|\Illuminate\Database\Query\Builder|mixed|null
     */
    public function query()
    {
        $query = $this->getQuery();

        if ($this->isQueued()) {
            $this->livewire = null;
        }

        return $query;
    }

    public function getQuery()
    {
        if (is_string($this->query)) {
            return EloquentSerializeFacade::unserialize($this->query);
        }

        if ($this->query) {
            return $this->query;
        }

        $livewire = $this->getLivewire();
        $model = $this->getModelInstance();

        $query = $this->columnsSource === 'table'
            ? invade($livewire)->getFilteredTableQuery()
            : $this->getModelClass()::query();

        if ($this->modifyQueryUsing) {
            $query = $this->modifyQueryUsing->getClosure()($query);
        }

        return $this->query = $query
            ->when(
                $this->recordIds,
                fn ($query) => $model->getKeyType() === 'string'
                    ? $query->whereIn($this->modelKeyName, $this->recordIds)
                    : $query->whereIntegerInRaw($this->modelKeyName, $this->recordIds)
            );
    }

    protected function getDefaultEvaluationParameters(): array
    {
        return [
            'livewire' => $this->getLivewire(),
            'livewireClass' => $this->getLivewireClass(),
            'model' => $this->getModelClass(),
            'resource' => $this->getResourceClass(),
            'recordIds' => $this->getRecordIds(),
            'query' => $this->getQuery(),
        ];
    }
}
