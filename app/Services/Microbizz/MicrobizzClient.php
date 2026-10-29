<?php

namespace App\Services\Microbizz;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Taler med Microbizz' API: én eller flere kommandoer i en kuvert med adgangsoplysningerne,
 * sendt som POST-parameteren "json". Kun læsning; systemet skriver aldrig til Microbizz.
 */
final class MicrobizzClient
{
    /** Højst så mange sider pr. kommando, så en fejl i Microbizz ikke giver en uendelig løkke. */
    private const MAX_PAGES = 500;

    public function isConfigured(): bool
    {
        $c = config('services.microbizz');

        return filled($c['contract'] ?? null) && filled($c['apikey'] ?? null)
            && filled($c['username'] ?? null) && filled($c['password'] ?? null);
    }

    /**
     * Send kommandoerne og få et resultat pr. kommando tilbage i samme rækkefølge.
     *
     * @param  list<array<string, mixed>>  $commands
     * @return array{results: list<array<string, mixed>>, date: ?string, time: ?string}
     */
    public function send(array $commands): array
    {
        if (! $this->isConfigured()) {
            throw new MicrobizzException('Microbizz er ikke sat op. Adgangsoplysningerne skal stå i .env.');
        }

        $c = config('services.microbizz');
        $envelope = [
            'contract' => (string) $c['contract'],
            'apikey' => (string) $c['apikey'],
            'username' => (string) $c['username'],
            'password' => (string) $c['password'],
            'remoteagent' => 'Lagerstyring',
            'haltonerror' => 1,
            'commands' => $commands,
        ];

        try {
            $response = Http::asForm()->acceptJson()->timeout($c['timeout'] ?? 60)
                ->post($c['endpoint'], ['json' => json_encode($envelope)]);
        } catch (ConnectionException) {
            throw new MicrobizzException('Kunne ikke få forbindelse til Microbizz. Prøv igen om lidt.');
        }

        if (! $response->successful()) {
            throw new MicrobizzException("Microbizz svarede med fejl {$response->status()}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new MicrobizzException('Microbizz svarede ikke med gyldige data.');
        }

        if ((int) ($body['status'] ?? 0) !== 1) {
            throw new MicrobizzException('Microbizz afviste forespørgslen: '.$this->message($body));
        }

        $results = array_values($body['results'] ?? []);

        foreach ($results as $i => $result) {
            if ((int) ($result['status'] ?? 0) !== 1) {
                $name = $commands[$i]['command'] ?? 'kommando';

                throw new MicrobizzException("Microbizz afviste {$name}: ".$this->message($result));
            }
        }

        return ['results' => $results, 'date' => $body['date'] ?? null, 'time' => $body['time'] ?? null];
    }

    /**
     * Kør en kommando, der kan give et delvist svar, og hent resten med resumekey.
     * Dato og tid er Microbizz' egen fra første svar.
     *
     * @param  array<string, mixed>  $params
     * @return array{items: list<array<string, mixed>>, date: ?string, time: ?string}
     */
    public function all(string $command, array $params, string $key): array
    {
        $items = [];
        $first = null;
        $resume = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $reply = $this->send([['command' => $command] + $params + ($resume ? ['resumekey' => $resume] : [])]);
            $first ??= $reply;
            $result = $reply['results'][0] ?? [];

            array_push($items, ...array_values($result[$key] ?? []));

            $resume = $result['resumekey'] ?? null;

            if (blank($resume)) {
                return ['items' => $items, 'date' => $first['date'], 'time' => $first['time']];
            }
        }

        throw new MicrobizzException("Microbizz blev ved med at sende flere sider for {$command}. Hentningen er stoppet.");
    }

    /** @param  array<string, mixed>  $reply */
    private function message(array $reply): string
    {
        $msg = $reply['msg'] ?? $reply['error'] ?? $reply['message'] ?? null;

        return is_string($msg) && $msg !== '' ? $msg : 'ukendt fejl';
    }
}
