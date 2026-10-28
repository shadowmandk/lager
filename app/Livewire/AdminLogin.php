<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Admin-login')]
#[Layout('layouts::app')]
class AdminLogin extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): mixed
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'admin-login:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'For mange forsøg. Prøv igen om '.RateLimiter::availableIn($key).' sekunder.');

            return null;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], remember: true)) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'Forkert e-mail eller adgangskode.');

            return null;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return $this->redirectIntended(route('admin'));
    }

    public function render()
    {
        return view('livewire.admin-login');
    }
}
