<?php

namespace App\Livewire;

use App\Services\StatisticsService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Statistik')]
class Statistics extends Component
{
    public const PERIODS = [7 => '7 dage', 30 => '30 dage', 90 => '90 dage'];

    #[Url(as: 'periode')]
    public int $days = 30;

    public function setDays(int $days): void
    {
        $this->days = array_key_exists($days, self::PERIODS) ? $days : 30;
    }

    public function render()
    {
        if (! array_key_exists($this->days, self::PERIODS)) {
            $this->days = 30;
        }

        $stats = app(StatisticsService::class);

        return view('livewire.statistics', [
            'periods' => self::PERIODS,
            'capacity' => $stats->capacity(),
            'summary' => $stats->summary($this->days),
            'weekly' => $stats->weekly(12),
            'weekdays' => $stats->weekdays(),
            'forecast' => $stats->forecast(),
        ]);
    }
}
