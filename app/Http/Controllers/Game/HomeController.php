<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Lobby\Identity;
use App\Lobby\LobbyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function show(Request $request, LobbyService $lobbies): Response
    {
        $name = Identity::name($request);
        if ($name === null) {
            return $this->editName($request, $lobbies);
        }

        $rejoin = null;
        $last = $request->cookie(Identity::LOBBY_COOKIE);
        $id = Identity::id($request);
        if (is_string($last) && $id !== null) {
            $lobby = $lobbies->find($last);
            if ($lobby !== null && $lobby->isMember($id)) {
                $rejoin = $lobby->code;
            }
        }

        return Inertia::render('Home', [
            'name' => $name,
            'rejoin' => $rejoin,
        ]);
    }

    public function editName(Request $request, LobbyService $lobbies): Response
    {
        $code = $request->query('code');
        $invite = null;
        if (is_string($code) && ($lobby = $lobbies->find($code)) !== null) {
            $invite = [
                'code' => $lobby->code,
                'host' => $lobby->hostName(),
                'aboard' => $lobby->headcount(),
            ];
        }

        return Inertia::render('Name', [
            'name' => Identity::name($request) ?? '',
            'invite' => $invite,
            'max' => (int) config('last-stop.name_max'),
        ]);
    }

    public function storeName(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.(int) config('last-stop.name_max')],
            'code' => ['nullable', 'string', 'size:'.(int) config('last-stop.code_length')],
        ]);
        $name = Identity::cleanName($validated['name']);
        if ($name === '') {
            return back()->withErrors(['name' => 'Type a name the crew can call you.']);
        }

        Cookie::queue(Cookie::make(Identity::NAME_COOKIE, $name, Identity::COOKIE_MINUTES, httpOnly: false, sameSite: 'lax'));

        return isset($validated['code'])
            ? redirect()->route('board.show', strtoupper($validated['code']))
            : redirect()->route('home');
    }
}
