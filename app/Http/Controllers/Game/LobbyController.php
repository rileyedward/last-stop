<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Lobby\Identity;
use App\Lobby\LobbyError;
use App\Lobby\LobbyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class LobbyController extends Controller
{
    public function __construct(private readonly LobbyService $lobbies) {}

    public function store(Request $request): RedirectResponse
    {
        [$id, $name] = $this->player($request);
        if ($name === null) {
            return redirect()->route('name.edit');
        }
        $lobby = $this->lobbies->create($id, $name);
        $this->remember($lobby->code);

        return redirect()->route('board.show', $lobby->code);
    }

    public function join(): Response
    {
        return Inertia::render('Join');
    }

    /**
     * Look up a code typed on the join screen.
     */
    public function find(Request $request): RedirectResponse
    {
        $code = strtoupper((string) $request->input('code'));
        $lobby = $this->lobbies->find($code);
        if ($lobby === null) {
            return back()->withErrors(['code' => "No train at {$code}. Check the letters, or ask for the invite link."]);
        }

        return $this->board($request, $code);
    }

    /**
     * Board a lobby from its page or invite link.
     */
    public function board(Request $request, string $code): RedirectResponse
    {
        [$id, $name] = $this->player($request);
        if ($name === null) {
            return redirect()->route('name.edit', ['code' => strtoupper($code)]);
        }

        try {
            $lobby = $this->lobbies->join($code, $id, $name);
        } catch (LobbyError $e) {
            return back()->withErrors(['code' => $e->getMessage(), 'reason' => $e->reason]);
        }
        $this->remember($lobby->code);

        return redirect()->route('board.show', $lobby->code);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function player(Request $request): array
    {
        return [(string) Identity::id($request), Identity::name($request)];
    }

    private function remember(string $code): void
    {
        Cookie::queue(Cookie::make(Identity::LOBBY_COOKIE, $code, Identity::COOKIE_MINUTES, httpOnly: true, sameSite: 'lax'));
    }
}
