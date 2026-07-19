<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Logout extends Component
{
    public function logout(): void
    {
        Auth::logout();
        Session::invalidate();
        Session::regenerateToken();

        $this->redirect(route('login'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.logout');
    }
}
