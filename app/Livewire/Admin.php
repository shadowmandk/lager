<?php

namespace App\Livewire;

use App\Models\Container;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Setting;
use App\Models\StorageRow;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Services\Actor;
use App\Services\LayoutService;
use App\Services\LocationLabel;
use App\Services\MoveRejected;
use App\Services\MoveService;
use App\Support\Backups;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Title('Opsætning')]
class Admin extends Component
{
    public const TABS = [
        'layout' => 'Lagre og rækker',
        'containers' => 'Containere',
        'customers' => 'Kunder',
        'people' => 'Medarbejdere',
        'settings' => 'Indstillinger og backup',
    ];

    #[Url]
    public string $tab = 'layout';

    // Layout
    public string $newWarehouse = '';

    /** @var array<int, array{name: string, is_buffer: bool}> pr. lager */
    public array $newZone = [];

    /** @var array<int, array{name: string, depth: int|string, height: int|string}> pr. område */
    public array $newRow = [];

    public ?int $editRowId = null;

    public array $rowEdit = ['name' => '', 'depth' => 1, 'height' => 1];

    // Kunder
    public ?int $editCustomerId = null;

    public array $customerForm = ['number' => '', 'name' => ''];

    public string $customerSearch = '';

    // Medarbejdere og admins
    public string $operatorName = '';

    public array $adminForm = ['name' => '', 'email' => '', 'password' => ''];

    // Containere
    public array $containerForm = ['number' => '', 'name' => '', 'customer_id' => '', 'status' => Container::PENDING];

    public string $containerSearch = '';

    // Indstillinger
    public int|string $minutesPerMove = 4;

    public ?string $notice = null;

    public string $noticeType = 'ok';

    public int $noticeId = 0;

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'layout';
        }

        $this->minutesPerMove = Setting::minutesPerMove();
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
        }
    }

    // ---------- Layout ----------

    public function addWarehouse(): void
    {
        $this->validate(['newWarehouse' => ['required', 'string', 'max:40']]);

        Warehouse::query()->create(['name' => trim($this->newWarehouse), 'sort' => (int) Warehouse::query()->max('sort') + 1]);
        $this->newWarehouse = '';
        $this->flash('Lageret er oprettet.');
    }

    public function renameWarehouse(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            $this->flash('Lagerets navn skal være mellem 1 og 40 tegn.', 'error');

            return;
        }

        Warehouse::query()->findOrFail($id)->update(['name' => $name]);
        LocationLabel::flush();
        $this->flash('Navnet er gemt.');
    }

    public function deleteWarehouse(int $id): void
    {
        $this->attempt(fn () => app(LayoutService::class)->deleteWarehouse(Warehouse::query()->findOrFail($id)), 'Lageret er slettet.');
    }

    public function addZone(int $warehouseId): void
    {
        $name = trim($this->newZone[$warehouseId]['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            $this->flash('Områdets navn skal være mellem 1 og 40 tegn.', 'error');

            return;
        }

        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $warehouse->zones()->create([
            'name' => $name,
            'is_buffer' => (bool) ($this->newZone[$warehouseId]['is_buffer'] ?? false),
            'sort' => (int) $warehouse->zones()->max('sort') + 1,
        ]);
        unset($this->newZone[$warehouseId]);
        $this->flash('Området er oprettet.');
    }

    public function toggleBuffer(int $zoneId): void
    {
        $zone = Zone::query()->findOrFail($zoneId);
        $zone->update(['is_buffer' => ! $zone->is_buffer]);
    }

    public function renameZone(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            $this->flash('Områdets navn skal være mellem 1 og 40 tegn.', 'error');

            return;
        }

        Zone::query()->findOrFail($id)->update(['name' => $name]);
        $this->flash('Navnet er gemt.');
    }

    public function deleteZone(int $id): void
    {
        $this->attempt(fn () => app(LayoutService::class)->deleteZone(Zone::query()->findOrFail($id)), 'Området er slettet.');
    }

    public function addRow(int $zoneId): void
    {
        $this->validate([
            "newRow.{$zoneId}.name" => ['required', 'string', 'max:20'],
            "newRow.{$zoneId}.depth" => ['required', 'integer', 'min:1', 'max:30'],
            "newRow.{$zoneId}.height" => ['required', 'integer', 'min:1', 'max:6'],
        ], [], [
            "newRow.{$zoneId}.name" => 'rækkens navn',
            "newRow.{$zoneId}.depth" => 'dybde',
            "newRow.{$zoneId}.height" => 'højde',
        ]);

        $data = $this->newRow[$zoneId];

        $this->attempt(function () use ($zoneId, $data) {
            app(LayoutService::class)->addRow(Zone::query()->findOrFail($zoneId), $data['name'], (int) $data['depth'], (int) $data['height']);
            unset($this->newRow[$zoneId]);
        }, 'Rækken er oprettet.');
    }

    public function editRow(int $id): void
    {
        $row = StorageRow::query()->findOrFail($id);
        $this->editRowId = $row->id;
        $this->rowEdit = ['name' => $row->name, 'depth' => $row->depth, 'height' => $row->height];
    }

    public function cancelRowEdit(): void
    {
        $this->editRowId = null;
    }

    public function saveRow(): void
    {
        $this->validate([
            'rowEdit.name' => ['required', 'string', 'max:20'],
            'rowEdit.depth' => ['required', 'integer', 'min:1', 'max:30'],
            'rowEdit.height' => ['required', 'integer', 'min:1', 'max:6'],
        ]);

        $this->attempt(function () {
            app(LayoutService::class)->updateRow(
                StorageRow::query()->findOrFail($this->editRowId),
                $this->rowEdit['name'], (int) $this->rowEdit['depth'], (int) $this->rowEdit['height'],
            );
            $this->editRowId = null;
        }, 'Rækken er gemt.');
    }

    public function deleteRow(int $id): void
    {
        $this->attempt(fn () => app(LayoutService::class)->deleteRow(StorageRow::query()->findOrFail($id)), 'Rækken er slettet.');
    }

    // ---------- Kunder ----------

    public function editCustomer(?int $id = null): void
    {
        $customer = $id ? Customer::query()->findOrFail($id) : null;
        $this->editCustomerId = $customer?->id ?? 0;
        $this->customerForm = ['number' => $customer->number ?? '', 'name' => $customer->name ?? ''];
        $this->resetValidation();
    }

    public function cancelCustomer(): void
    {
        $this->editCustomerId = null;
    }

    public function saveCustomer(): void
    {
        $id = $this->editCustomerId ?: null;

        $data = $this->validate([
            'customerForm.number' => ['required', 'string', 'max:30', Rule::unique('customers', 'number')->ignore($id)],
            'customerForm.name' => ['required', 'string', 'max:100'],
        ])['customerForm'];

        $data = array_map('trim', $data);

        $id ? Customer::query()->findOrFail($id)->update($data) : Customer::query()->create($data);

        $this->editCustomerId = null;
        $this->flash('Kunden er gemt.');
    }

    public function deleteCustomer(int $id): void
    {
        $customer = Customer::query()->findOrFail($id);

        if ($customer->containers()->exists()) {
            $this->flash("{$customer->name} har containere tilknyttet og kan ikke slettes.", 'error');

            return;
        }

        $customer->delete();
        $this->flash('Kunden er slettet.');
    }

    // ---------- Medarbejdere ----------

    public function addOperator(): void
    {
        $this->validate(['operatorName' => ['required', 'string', 'max:40']]);
        Operator::query()->create(['name' => trim($this->operatorName)]);
        $this->operatorName = '';
        $this->flash('Medarbejderen er oprettet.');
    }

    public function renameOperator(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            $this->flash('Navnet skal være mellem 1 og 40 tegn.', 'error');

            return;
        }

        Operator::query()->findOrFail($id)->update(['name' => $name]);
        $this->flash('Navnet er gemt.');
    }

    public function toggleOperator(int $id): void
    {
        $operator = Operator::query()->findOrFail($id);
        $operator->update(['active' => ! $operator->active]);
    }

    public function addAdmin(): void
    {
        $data = $this->validate([
            'adminForm.name' => ['required', 'string', 'max:60'],
            'adminForm.email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'adminForm.password' => ['required', 'string', 'min:10'],
        ])['adminForm'];

        User::query()->create($data);
        $this->adminForm = ['name' => '', 'email' => '', 'password' => ''];
        $this->flash('Administratoren er oprettet.');
    }

    public function deleteAdmin(int $id): void
    {
        if ($id === auth()->id()) {
            $this->flash('Du kan ikke slette dig selv.', 'error');

            return;
        }

        User::query()->findOrFail($id)->delete();
        $this->flash('Administratoren er slettet.');
    }

    // ---------- Containere ----------

    public function createContainer(): void
    {
        $data = $this->validate([
            'containerForm.number' => ['required', 'string', 'max:30'],
            'containerForm.name' => ['nullable', 'string', 'max:100'],
            'containerForm.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'containerForm.status' => ['required', Rule::in([Container::PENDING, Container::DELIVERED])],
        ])['containerForm'];

        try {
            $container = app(MoveService::class)->create(
                $data['number'], $data['name'] ?? null, $data['customer_id'] ? (int) $data['customer_id'] : null,
                $data['status'], Actor::admin(auth()->user()),
            );
        } catch (MoveRejected $e) {
            $this->addError('containerForm.number', $e->getMessage());

            return;
        }

        $this->containerForm = ['number' => '', 'name' => '', 'customer_id' => $data['customer_id'] ?? '', 'status' => $data['status']];
        $this->flash($container->status === Container::PENDING
            ? "Container {$container->number} er oprettet. Den står under “Venter på plads” på tavlen."
            : "Container {$container->number} er oprettet som udleveret.");
    }

    public function deleteContainer(int $id): void
    {
        $container = Container::query()->findOrFail($id);

        if ($container->occupiesSlot()) {
            $this->flash("Container {$container->number} står på lageret eller er i transit. Udlevér den eller flyt den væk fra tavlen først.", 'error');

            return;
        }

        $container->delete();
        $this->flash("Container {$container->number} er fjernet fra systemet. Historikken bevares i loggen.");
    }

    // ---------- Indstillinger ----------

    public function saveSettings(): void
    {
        $this->validate(['minutesPerMove' => ['required', 'integer', 'min:0', 'max:120']]);
        Setting::put(Setting::MINUTES_PER_MOVE, (string) (int) $this->minutesPerMove);
        $this->flash('Indstillingen er gemt.');
    }

    public function runBackup(): void
    {
        $this->attempt(fn () => Backups::run(), 'Der er taget en backup.');
    }

    public function render()
    {
        $data = ['tabs' => self::TABS];

        $data += match ($this->tab) {
            'layout' => [
                'warehouses' => Warehouse::query()->with('zones.rows')->orderBy('sort')->orderBy('id')->get(),
                'counts' => Container::query()->whereIn('status', Container::ON_SLOT)
                    ->selectRaw('storage_row_id, count(*) as n')->groupBy('storage_row_id')->pluck('n', 'storage_row_id'),
            ],
            'customers' => [
                'customers' => Customer::query()->withCount('containers')
                    ->when($this->customerSearch !== '', fn ($q) => $q->where(fn ($q) => $q
                        ->where('name', 'like', '%'.$this->customerSearch.'%')
                        ->orWhere('number', 'like', '%'.$this->customerSearch.'%')))
                    ->orderBy('name')->get(),
            ],
            'people' => [
                'operators' => Operator::query()->orderByDesc('active')->orderBy('name')->get(),
                'admins' => User::query()->orderBy('name')->get(),
            ],
            'containers' => [
                'customerOptions' => Customer::query()->orderBy('name')->get(['id', 'name', 'number']),
                'containers' => Container::query()->with('customer')->search($this->containerSearch)
                    ->orderBy('number')->limit(100)->get(),
                'totals' => Container::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            ],
            'settings' => [
                'backups' => array_slice(Backups::all(), 0, 10),
            ],
        };

        return view('livewire.admin', $data);
    }

    private function attempt(callable $callback, string $success): void
    {
        try {
            $callback();
            $this->flash($success);
        } catch (RuntimeException $e) {
            $this->flash($e->getMessage(), 'error');
        }
    }

    private function flash(string $message, string $type = 'ok'): void
    {
        $this->notice = $message;
        $this->noticeType = $type;
        $this->noticeId++;
    }
}
