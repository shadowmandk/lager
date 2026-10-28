<div>
    <header class="sticky top-0 z-30 bg-slate-800 text-white shadow">
        <div class="flex flex-wrap items-center gap-2 px-4 py-2">
            <h1 class="mr-2 text-lg font-semibold">Opsætning</h1>
            <nav class="flex flex-wrap gap-1">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class(['nav-btn', 'nav-btn-active' => $tab === $key])>{{ $label }}</button>
                @endforeach
            </nav>
            <div class="ml-auto flex items-center gap-2">
                <a href="{{ route('board') }}" class="nav-btn">Til tavlen</a>
                <span class="hidden text-sm text-slate-300 md:inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="nav-btn">Log ud</button>
                </form>
            </div>
        </div>
    </header>

    <div class="mx-auto max-w-6xl space-y-4 p-4">

        {{-- ================= Lagre og rækker ================= --}}
        @if ($tab === 'layout')
            <p class="text-slate-600">
                Et lager består af områder, og et område af rækker. En række har en dybde (antal containere fra indgangen og ind)
                og en højde (antal containere i stakken). En buffer er bare rækker med dybde 1.
                Rækker kan kun gøres mindre eller slettes, når de pladser, der forsvinder, er tomme.
            </p>

            @foreach ($warehouses as $w)
                <section wire:key="wh-{{ $w->id }}" class="space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div class="flex flex-wrap items-center gap-2" x-data="{ name: @js($w->name) }">
                        <input type="text" x-model="name" class="h-11 text-xl font-semibold" aria-label="Lagerets navn">
                        <button type="button" class="btn-secondary h-11" @click="$wire.renameWarehouse({{ $w->id }}, name)">Gem navn</button>
                        <button type="button" class="btn-danger ml-auto h-11"
                                wire:click="deleteWarehouse({{ $w->id }})" wire:confirm="Slet {{ $w->name }} med alle områder og rækker?">Slet lager</button>
                    </div>

                    @foreach ($w->zones as $zone)
                        <div wire:key="zone-{{ $zone->id }}" class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                            <div class="flex flex-wrap items-center gap-2" x-data="{ name: @js($zone->name) }">
                                <input type="text" x-model="name" class="h-10 font-semibold" aria-label="Områdets navn">
                                <button type="button" class="btn-secondary h-10" @click="$wire.renameZone({{ $zone->id }}, name)">Gem navn</button>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" @checked($zone->is_buffer) wire:click="toggleBuffer({{ $zone->id }})" class="h-5 w-5">
                                    Buffer (bruges til at parkere containere)
                                </label>
                                <button type="button" class="btn-danger ml-auto h-10"
                                        wire:click="deleteZone({{ $zone->id }})" wire:confirm="Slet området {{ $zone->name }} med alle rækker?">Slet område</button>
                            </div>

                            <table class="mt-3 w-full text-left">
                                <thead class="text-sm text-slate-500">
                                    <tr><th class="py-1">Række</th><th>Dybde</th><th>Højde</th><th>Pladser</th><th>Optaget</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($zone->rows as $row)
                                        <tr wire:key="row-{{ $row->id }}" class="border-t border-slate-200">
                                            @if ($editRowId === $row->id)
                                                <td class="py-2"><input type="text" wire:model="rowEdit.name" class="h-10 w-28"></td>
                                                <td><input type="number" min="1" max="30" wire:model="rowEdit.depth" class="h-10 w-20"></td>
                                                <td><input type="number" min="1" max="6" wire:model="rowEdit.height" class="h-10 w-20"></td>
                                                <td colspan="2" class="text-sm text-red-700">
                                                    @error('rowEdit.name') {{ $message }} @enderror
                                                    @error('rowEdit.depth') {{ $message }} @enderror
                                                    @error('rowEdit.height') {{ $message }} @enderror
                                                </td>
                                                <td class="space-x-1 text-right">
                                                    <button type="button" wire:click="saveRow" class="btn-primary h-10">Gem</button>
                                                    <button type="button" wire:click="cancelRowEdit" class="btn-secondary h-10">Annullér</button>
                                                </td>
                                            @else
                                                <td class="py-2 font-semibold">{{ $row->name }}</td>
                                                <td>{{ $row->depth }}</td>
                                                <td>{{ $row->height }}</td>
                                                <td>{{ $row->capacity() }}</td>
                                                <td>{{ $counts[$row->id] ?? 0 }}</td>
                                                <td class="space-x-1 text-right">
                                                    <button type="button" wire:click="editRow({{ $row->id }})" class="btn-secondary h-10">Ret</button>
                                                    <button type="button" wire:click="deleteRow({{ $row->id }})" wire:confirm="Slet række {{ $row->name }}?" class="btn-danger h-10">Slet</button>
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                    <tr class="border-t border-slate-200">
                                        <td class="py-2"><input type="text" wire:model="newRow.{{ $zone->id }}.name" placeholder="D" class="h-10 w-28" aria-label="Ny rækkes navn"></td>
                                        <td><input type="number" min="1" max="30" wire:model="newRow.{{ $zone->id }}.depth" placeholder="10" class="h-10 w-20" aria-label="Dybde"></td>
                                        <td><input type="number" min="1" max="6" wire:model="newRow.{{ $zone->id }}.height" placeholder="3" class="h-10 w-20" aria-label="Højde"></td>
                                        <td colspan="2" class="text-sm text-red-700">
                                            @error("newRow.{$zone->id}.name") {{ $message }} @enderror
                                            @error("newRow.{$zone->id}.depth") {{ $message }} @enderror
                                            @error("newRow.{$zone->id}.height") {{ $message }} @enderror
                                        </td>
                                        <td class="text-right"><button type="button" wire:click="addRow({{ $zone->id }})" class="btn-secondary h-10">Tilføj række</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endforeach

                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="newZone.{{ $w->id }}.name" placeholder="Nyt område, f.eks. Rækker eller Buffer" class="h-10 min-w-72" aria-label="Nyt områdes navn">
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="newZone.{{ $w->id }}.is_buffer" class="h-5 w-5"> Buffer
                        </label>
                        <button type="button" wire:click="addZone({{ $w->id }})" class="btn-secondary h-10">Tilføj område</button>
                    </div>
                </section>
            @endforeach

            <form wire:submit="addWarehouse" class="flex flex-wrap items-center gap-2 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <input type="text" wire:model="newWarehouse" placeholder="Lager 3" class="h-11" aria-label="Nyt lagers navn">
                <button type="submit" class="btn-primary h-11">Tilføj lager</button>
                @error('newWarehouse') <span class="text-red-700">{{ $message }}</span> @enderror
            </form>
        @endif

        {{-- ================= Containere ================= --}}
        @if ($tab === 'containers')
            <form wire:submit="createContainer" class="grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200 md:grid-cols-6">
                <div class="md:col-span-6">
                    <h2 class="text-lg font-semibold">Nye containere</h2>
                    <p class="text-sm text-slate-600">Udfyld “Til nummer” for at oprette en hel serie på én gang, f.eks. 4101 til 4120. Numre, der allerede findes, springes over.</p>
                </div>
                <label class="block">
                    <span class="text-sm text-slate-600">Nummer</span>
                    <input type="text" wire:model="containerForm.number" class="mt-1 h-11 w-full uppercase" required>
                </label>
                <label class="block">
                    <span class="text-sm text-slate-600">Til nummer (valgfrit)</span>
                    <input type="text" wire:model="containerForm.to" class="mt-1 h-11 w-full uppercase" placeholder="Kun ved serie">
                </label>
                <label class="block">
                    <span class="text-sm text-slate-600">Navn</span>
                    <input type="text" wire:model="containerForm.name" class="mt-1 h-11 w-full" placeholder="Værktøj">
                </label>
                <label class="block">
                    <span class="text-sm text-slate-600">Kunde</span>
                    <select wire:model="containerForm.customer_id" class="mt-1 h-11 w-full">
                        <option value="">Ingen kunde</option>
                        @foreach ($customerOptions as $cu)
                            <option value="{{ $cu->id }}">{{ $cu->name }} ({{ $cu->number }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm text-slate-600">Hvor er den?</span>
                    <select wire:model="containerForm.status" class="mt-1 h-11 w-full">
                        <option value="pending">Hjemme, skal placeres på tavlen</option>
                        <option value="delivered">Udleveret til kunde</option>
                    </select>
                </label>
                <div class="flex items-end"><button type="submit" class="btn-primary h-11 w-full">Opret</button></div>
                @error('containerForm.number') <p class="text-red-700 md:col-span-6">{{ $message }}</p> @enderror
                @error('containerForm.customer_id') <p class="text-red-700 md:col-span-6">{{ $message }}</p> @enderror
            </form>

            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold">Alle containere</h2>
                    <span class="text-sm text-slate-500">
                        {{ $totals['stored'] ?? 0 }} på lager · {{ $totals['delivered'] ?? 0 }} udleveret · {{ $totals['pending'] ?? 0 }} venter på plads
                    </span>
                    <input type="search" wire:model.live.debounce.300ms="containerSearch" placeholder="Søg" class="ml-auto h-10 w-56">
                </div>
                <table class="w-full text-left">
                    <thead class="text-sm text-slate-500"><tr><th class="py-1">Nummer</th><th>Navn</th><th>Kunde</th><th>Placering</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($containers as $c)
                            <tr wire:key="c-{{ $c->id }}" class="border-t border-slate-100">
                                <td class="py-2 font-semibold">{{ $c->number }}</td>
                                <td>{{ $c->name }}</td>
                                <td>{{ $c->customer?->name }}</td>
                                <td class="text-sm">{{ \App\Services\LocationLabel::forContainer($c) }}</td>
                                <td class="text-right">
                                    @unless ($c->occupiesSlot())
                                        <button type="button" wire:click="deleteContainer({{ $c->id }})"
                                                wire:confirm="Fjern container {{ $c->number }} fra systemet? Historikken bevares i loggen." class="btn-danger h-9 text-sm">Fjern</button>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-sm text-slate-500">Viser højst 100. Navn og kunde rettes fra tavlen, så det kommer i loggen.</p>
            </section>
        @endif

        {{-- ================= Kunder ================= --}}
        @if ($tab === 'customers')
            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold">Kunder</h2>
                    <input type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="Søg" class="h-10 w-56">
                    <button type="button" wire:click="editCustomer" class="btn-primary ml-auto h-10">Ny kunde</button>
                </div>

                @if ($editCustomerId !== null)
                    <form wire:submit="saveCustomer" class="mb-4 grid gap-3 rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200 md:grid-cols-4">
                        <label class="block">
                            <span class="text-sm text-slate-600">Kundenummer</span>
                            <input type="text" wire:model="customerForm.number" class="mt-1 h-11 w-full" required>
                        </label>
                        <label class="block md:col-span-2">
                            <span class="text-sm text-slate-600">Navn</span>
                            <input type="text" wire:model="customerForm.name" class="mt-1 h-11 w-full" required>
                        </label>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="btn-primary h-11 flex-1">Gem</button>
                            <button type="button" wire:click="cancelCustomer" class="btn-secondary h-11">Annullér</button>
                        </div>
                        @error('customerForm.number') <p class="text-red-700 md:col-span-4">{{ $message }}</p> @enderror
                        @error('customerForm.name') <p class="text-red-700 md:col-span-4">{{ $message }}</p> @enderror
                    </form>
                @endif

                <table class="w-full text-left">
                    <thead class="text-sm text-slate-500"><tr><th class="py-1">Nummer</th><th>Navn</th><th>Containere</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($customers as $cu)
                            <tr wire:key="cu-{{ $cu->id }}" class="border-t border-slate-100">
                                <td class="py-2">{{ $cu->number }}</td>
                                <td class="font-semibold">
                                    <span class="mr-2 inline-block h-3 w-3 rounded-sm align-middle" style="background: {{ $cu->color()['side'] }}"></span>{{ $cu->name }}
                                </td>
                                <td>{{ $cu->containers_count }}</td>
                                <td class="space-x-1 text-right">
                                    <button type="button" wire:click="editCustomer({{ $cu->id }})" class="btn-secondary h-9 text-sm">Ret</button>
                                    @if ($cu->containers_count === 0)
                                        <button type="button" wire:click="deleteCustomer({{ $cu->id }})" wire:confirm="Slet {{ $cu->name }}?" class="btn-danger h-9 text-sm">Slet</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-3 text-slate-500">Ingen kunder.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        @endif

        {{-- ================= Medarbejdere ================= --}}
        @if ($tab === 'people')
            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-semibold">Medarbejdere ved tavlen</h2>
                <p class="text-sm text-slate-600">De vælger bare deres navn på tabletten. Deaktiverede vises ikke, men bevares i loggen.</p>
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($operators as $op)
                        <li wire:key="op-{{ $op->id }}" class="flex flex-wrap items-center gap-2 py-2" x-data="{ name: @js($op->name) }">
                            <input type="text" x-model="name" class="h-10" aria-label="Navn">
                            <button type="button" class="btn-secondary h-10" @click="$wire.renameOperator({{ $op->id }}, name)">Gem navn</button>
                            <button type="button" wire:click="toggleOperator({{ $op->id }})" @class(['h-10 ml-auto', 'btn-secondary' => $op->active, 'btn-primary' => ! $op->active])>
                                {{ $op->active ? 'Deaktivér' : 'Aktivér' }}
                            </button>
                            @unless ($op->active) <span class="text-sm text-slate-500">Deaktiveret</span> @endunless
                        </li>
                    @endforeach
                </ul>
                <form wire:submit="addOperator" class="mt-3 flex flex-wrap gap-2">
                    <input type="text" wire:model="operatorName" placeholder="Navn" class="h-11" aria-label="Ny medarbejders navn">
                    <button type="submit" class="btn-primary h-11">Tilføj medarbejder</button>
                    @error('operatorName') <span class="text-red-700">{{ $message }}</span> @enderror
                </form>
            </section>

            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-semibold">Administratorer</h2>
                <p class="text-sm text-slate-600">Kan logge ind på opsætningen med e-mail og adgangskode.</p>
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach ($admins as $u)
                        <li wire:key="u-{{ $u->id }}" class="flex items-center gap-3 py-2">
                            <span class="font-semibold">{{ $u->name }}</span>
                            <span class="text-slate-600">{{ $u->email }}</span>
                            @if ($u->id !== auth()->id())
                                <button type="button" wire:click="deleteAdmin({{ $u->id }})" wire:confirm="Slet {{ $u->name }}?" class="btn-danger ml-auto h-9 text-sm">Slet</button>
                            @else
                                <span class="ml-auto text-sm text-slate-500">Dig</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <form wire:submit="addAdmin" class="mt-3 grid gap-2 md:grid-cols-4">
                    <input type="text" wire:model="adminForm.name" placeholder="Navn" class="h-11" aria-label="Navn">
                    <input type="email" wire:model="adminForm.email" placeholder="navn@firma.dk" class="h-11" aria-label="E-mail" autocomplete="off">
                    <input type="password" wire:model="adminForm.password" placeholder="Adgangskode, mindst 10 tegn" class="h-11" aria-label="Adgangskode" autocomplete="new-password">
                    <button type="submit" class="btn-primary h-11">Tilføj administrator</button>
                    @foreach (['adminForm.name', 'adminForm.email', 'adminForm.password'] as $f)
                        @error($f) <p class="text-red-700 md:col-span-4">{{ $message }}</p> @enderror
                    @endforeach
                </form>
            </section>
        @endif

        {{-- ================= Indstillinger og backup ================= --}}
        @if ($tab === 'settings')
            <form wire:submit="saveSettings" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <label class="block">
                    <span class="text-sm text-slate-600">Minutter pr. flytning</span>
                    <input type="number" min="0" max="120" wire:model="minutesPerMove" class="mt-1 h-11 w-28">
                </label>
                <button type="submit" class="btn-primary h-11">Gem</button>
                <p class="w-full text-sm text-slate-600">Bruges til at regne ud, hvor lang tid det tager at komme ind til en container.</p>
                @error('minutesPerMove') <p class="w-full text-red-700">{{ $message }}</p> @enderror
            </form>

            <section class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold">Backup</h2>
                    <button type="button" wire:click="runBackup" class="btn-secondary ml-auto h-10">Tag backup nu</button>
                </div>
                <p class="text-sm text-slate-600">Der tages automatisk en backup hver nat kl. 02. De seneste {{ \App\Support\Backups::KEEP }} gemmes.</p>
                <ul class="mt-3 divide-y divide-slate-100">
                    @forelse ($backups as $b)
                        <li class="flex items-center gap-3 py-2">
                            <span class="font-mono text-sm">{{ $b['name'] }}</span>
                            <span class="text-sm text-slate-500">{{ number_format($b['size'] / 1024, 0, ',', '.') }} KB</span>
                            <a href="{{ route('admin.backup', $b['name']) }}" class="btn-secondary ml-auto h-9 text-sm">Hent</a>
                        </li>
                    @empty
                        <li class="py-2 text-slate-500">Ingen backups endnu.</li>
                    @endforelse
                </ul>
            </section>
        @endif
    </div>

    @if ($notice)
        <div wire:key="notice-{{ $noticeId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show"
             @class([
                 'fixed bottom-6 left-1/2 z-50 w-[min(92vw,40rem)] -translate-x-1/2 rounded-xl px-5 py-3 text-lg font-medium shadow-lg',
                 'bg-emerald-600 text-white' => $noticeType === 'ok',
                 'bg-red-600 text-white' => $noticeType === 'error',
             ]) @click="show = false" role="status">{{ $notice }}</div>
    @endif
</div>
