<?php

namespace App\Services\Microbizz;

use App\Models\Activity;
use App\Models\Container;
use App\Models\Customer;
use App\Models\MicrobizzSync;
use App\Models\Setting;
use App\Services\Actor;
use App\Services\MoveService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Henter kunder og containere fra Microbizz og retter dem til her.
 *
 * Microbizz bestemmer nummer, navn og kunde. Hentningen ændrer aldrig en eksisterende containers
 * status eller plads; den placeres stadig kun via tavlen. Kun ændringer siden sidst hentes,
 * og udgangspunktet flyttes kun, når hentningen lykkes.
 */
final class MicrobizzImporter
{
    /** Overlap med forrige hentning, så ændringer lige omkring skiftet ikke tabes. */
    private const OVERLAP_MINUTES = 5;

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly MicrobizzClient $client,
        private readonly MoveService $moves,
    ) {}

    public function run(string $trigger): MicrobizzSync
    {
        if (! $this->client->isConfigured()) {
            throw new MicrobizzException('Microbizz er ikke sat op. Adgangsoplysningerne skal stå i .env.');
        }

        $lock = Cache::lock('microbizz-import', 900);

        if (! $lock->get()) {
            throw new MicrobizzException('Der hentes allerede fra Microbizz. Prøv igen om lidt.');
        }

        $this->notes = [];
        $run = MicrobizzSync::query()->create(['trigger' => $trigger, 'started_at' => now()]);

        try {
            $this->customers($run);
            $this->tools($run);
            $run->status = 'ok';
        } catch (MicrobizzException $e) {
            $run->status = 'error';
            $run->error = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $run->status = 'error';
            $run->error = 'Uventet fejl under hentningen. Detaljerne står i Laravels logfil.';
        } finally {
            $run->notes = array_slice($this->notes, 0, 50);
            $run->finished_at = now();
            $run->save();
            $lock->release();
        }

        return $run;
    }

    /** Næste hentning tager alt udstyr forfra, f.eks. efter udvælgelsen er ændret. */
    public static function resetTools(): void
    {
        Setting::put(MicrobizzSettings::TOOLS_SINCE, null);
    }

    public static function resetAll(): void
    {
        Setting::put(MicrobizzSettings::CUSTOMERS_SINCE, null);
        self::resetTools();
    }

    /**
     * Til "Test forbindelse": første side udstyr og hvilke grupper og klasser der findes.
     *
     * @return array{count: int, more: bool, groups: array<string, int>, classes: array<string, int>, samples: list<array<string, string>>}
     */
    public function probe(): array
    {
        $result = $this->client->send([
            ['command' => 'GetToolsByLastChange', 'changedate' => '2000-01-01', 'skip_deleted' => 1],
        ])['results'][0];

        $tools = array_values($result['tools'] ?? []);
        $count = fn (string $field) => collect($tools)
            ->map(fn ($t) => trim((string) ($t[$field] ?? '')) ?: '(tom)')
            ->countBy()->sortDesc()->take(20)->all();

        return [
            'count' => count($tools),
            'more' => filled($result['resumekey'] ?? null),
            'groups' => $count('group'),
            'classes' => $count('class'),
            'samples' => collect($tools)->take(5)->map(fn ($t) => collect(['groupid', ...array_keys(MicrobizzSettings::NUMBER_FIELDS), 'group', 'class', 'model'])
                ->mapWithKeys(fn ($f) => [$f => (string) ($t[$f] ?? '')])->all())->values()->all(),
        ];
    }

    private function customers(MicrobizzSync $run): void
    {
        $reply = $this->client->all('GetCustomersByLastChange', $this->since(MicrobizzSettings::CUSTOMERS_SINCE), 'customers');

        foreach ($reply['items'] as $data) {
            $this->upsertCustomer($data, $run);
        }

        $this->advance(MicrobizzSettings::CUSTOMERS_SINCE, $reply);
    }

    private function tools(MicrobizzSync $run): void
    {
        if (! MicrobizzSettings::hasFilter()) {
            $this->notes[] = 'Udstyr er ikke hentet: vælg under Opsætning, hvilket udstyr i Microbizz der er containere.';

            return;
        }

        $reply = $this->client->all('GetToolsByLastChange', $this->since(MicrobizzSettings::TOOLS_SINCE), 'tools');

        $this->fetchMissingCustomers($reply['items'], $run);

        foreach ($reply['items'] as $tool) {
            $this->upsertContainer($tool, $run);
        }

        $this->advance(MicrobizzSettings::TOOLS_SINCE, $reply);
    }

    /** @param  array<string, mixed>  $data */
    private function upsertCustomer(array $data, MicrobizzSync $run): ?Customer
    {
        $id = (int) ($data['id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        $number = mb_substr(trim((string) ($data['customernumber'] ?? '')), 0, 30) ?: "MB-{$id}";
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 100) ?: $number;
        $inactive = (int) ($data['isdeleted'] ?? 0) > 0;

        $customer = Customer::query()->where('microbizz_id', $id)->first()
            ?? Customer::query()->whereNull('microbizz_id')->where('number', $number)->first();

        $clash = Customer::query()->where('number', $number)
            ->when($customer, fn ($q) => $q->whereKeyNot($customer->id))
            ->exists();

        if ($clash) {
            $this->notes[] = "Kunden {$name}: kundenummer {$number} bruges af en anden kunde her og er sprunget over.";

            return $customer;
        }

        if ($customer === null) {
            if ($inactive) {
                return null;
            }

            $run->customers_created++;

            return Customer::query()->create(['microbizz_id' => $id, 'number' => $number, 'name' => $name]);
        }

        $customer->fill(['microbizz_id' => $id, 'number' => $number, 'name' => $name, 'microbizz_inactive' => $inactive]);

        if ($customer->isDirty()) {
            $customer->save();
            $run->customers_updated++;
        }

        return $customer;
    }

    /**
     * Udstyr kan være tilknyttet kunder, der ikke er ændret siden sidst og derfor ikke kom med.
     * De hentes enkeltvis, mange i samme forespørgsel.
     *
     * @param  list<array<string, mixed>>  $tools
     */
    private function fetchMissingCustomers(array $tools, MicrobizzSync $run): void
    {
        $ids = collect($tools)
            ->filter(fn ($t) => MicrobizzSettings::isContainer($t))
            ->map(fn ($t) => (int) ($t['attachedcustomerid'] ?? 0))
            ->filter()->unique()->values();

        $known = Customer::query()->whereIn('microbizz_id', $ids)->pluck('microbizz_id')->all();
        $missing = $ids->diff($known)->values();

        foreach ($missing->chunk(50) as $chunk) {
            $reply = $this->client->send($chunk->map(fn ($id) => ['command' => 'GetCustomerByID', 'customerid' => $id])->values()->all());

            foreach ($reply['results'] as $result) {
                foreach ($result['customers'] ?? [] as $data) {
                    $this->upsertCustomer($data, $run);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $tool */
    private function upsertContainer(array $tool, MicrobizzSync $run): void
    {
        $id = (int) ($tool['id'] ?? 0);

        if ($id === 0) {
            return;
        }

        $isContainer = MicrobizzSettings::isContainer($tool) && (int) ($tool['isdeleted'] ?? 0) === 0;
        $field = MicrobizzSettings::numberField();
        $number = mb_substr(Container::normalizeNumber((string) ($tool[$field] ?? '')), 0, 30);

        $container = Container::withTrashed()->where('microbizz_id', $id)->first();

        if ($container === null && $isContainer && $number !== '') {
            $container = Container::withTrashed()->whereNull('microbizz_id')->whereRaw('upper(number) = ?', [$number])->first();
        }

        if (! $isContainer) {
            if ($container !== null) {
                $this->remove($container, $run);
            }

            return;
        }

        $label = trim((string) ($tool['name'] ?? '')) ?: "id {$id}";

        if ($number === '') {
            $this->notes[] = 'Udstyret '.$label.' har intet containernummer i feltet '.MicrobizzSettings::NUMBER_FIELDS[$field].' og er sprunget over.';

            return;
        }

        $clash = Container::withTrashed()->whereRaw('upper(number) = ?', [$number])
            ->when($container, fn ($q) => $q->whereKeyNot($container->id))
            ->exists();

        if ($clash) {
            $this->notes[] = "Containernummer {$number} fra Microbizz findes allerede her på en anden container og er sprunget over.";

            return;
        }

        $nameField = MicrobizzSettings::nameField();
        $name = $nameField === 'none' ? null : (mb_substr(trim((string) ($tool[$nameField] ?? '')), 0, 100) ?: null);
        $customerMbId = (int) ($tool['attachedcustomerid'] ?? 0);
        $customerId = $customerMbId ? Customer::query()->where('microbizz_id', $customerMbId)->value('id') : null;

        $this->moves->transaction(function () use ($container, $id, $number, $name, $customerId, $run) {
            if ($container === null) {
                $created = $this->moves->create($number, $name, $customerId, MicrobizzSettings::newStatus(), Actor::microbizz());
                $created->update(['microbizz_id' => $id]);
                $run->containers_created++;

                return;
            }

            if ($container->trashed()) {
                $container->restore();
            }

            $changed = $container->number !== $number || $container->name !== $name || $container->customer_id !== $customerId;
            $container->microbizz_id = $id;
            $container->microbizz_removed_at = null;

            if ($changed) {
                $container->number = $number;
                $this->moves->apply($container, Activity::SYNCED, Actor::microbizz(), customerId: $customerId, name: $name);
                $run->containers_updated++;
            } elseif ($container->isDirty()) {
                $container->save();
            }
        });
    }

    /** Slettet, deaktiveret eller ude af gruppen i Microbizz. En container på en plads bliver, men får en advarsel. */
    private function remove(Container $container, MicrobizzSync $run): void
    {
        if ($container->trashed()) {
            return;
        }

        if ($container->occupiesSlot()) {
            if ($container->microbizz_removed_at === null) {
                $container->update(['microbizz_removed_at' => now()]);
                $run->containers_removed++;
                $this->notes[] = "Container {$container->number} står på lageret, men er fjernet i Microbizz. Den bliver på tavlen, til kontoret har taget stilling.";
            }

            return;
        }

        $container->delete();
        $run->containers_removed++;
    }

    /** @return array<string, string> */
    private function since(string $key): array
    {
        $since = Setting::get($key);

        if (blank($since)) {
            return ['changedate' => '2000-01-01'];
        }

        $at = CarbonImmutable::parse($since);

        return ['changedate' => $at->toDateString(), 'changetime' => $at->format('H:i:s')];
    }

    /** @param  array{date: ?string, time: ?string}  $reply */
    private function advance(string $key, array $reply): void
    {
        $at = filled($reply['date']) ? CarbonImmutable::parse($reply['date'].' '.($reply['time'] ?? '00:00:00')) : CarbonImmutable::now();

        Setting::put($key, $at->subMinutes(self::OVERLAP_MINUTES)->toDateTimeString());
    }
}
