<?php

namespace App\Http\Controllers\Game;

use App\Game\Engine\RuleViolation;
use App\Http\Controllers\Controller;
use App\Lobby\BoardView;
use App\Lobby\Identity;
use App\Lobby\LobbyError;
use App\Lobby\LobbyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page for one lobby: the lobby itself, the game, the final screen, or
 * the platform for people who arrived mid-trip. Every action posts here and
 * comes back to the same page with a fresh view.
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly LobbyService $lobbies,
        private readonly BoardView $views,
    ) {}

    public function show(Request $request, string $code): Response|RedirectResponse
    {
        $code = strtoupper($code);
        $this->lobbies->tick($code);
        $lobby = $this->lobbies->find($code);
        $name = Identity::name($request);
        $id = (string) Identity::id($request);

        if ($lobby === null) {
            return Inertia::render('Gone', ['code' => $code]);
        }
        if ($name === null && ! $lobby->isMember($id)) {
            return redirect()->route('name.edit', ['code' => $code]);
        }

        return Inertia::render('Board', [
            'state' => fn () => $this->views->forPlayer($lobby, $id, $name, $this->lobbies->now()),
            'heartbeat' => (int) config('last-stop.heartbeat_seconds'),
        ]);
    }

    public function station(string $code): Response
    {
        $code = strtoupper($code);
        $this->lobbies->tick($code);
        $lobby = $this->lobbies->find($code);
        if ($lobby === null) {
            return Inertia::render('Gone', ['code' => $code]);
        }

        return Inertia::render('Station', [
            'state' => fn () => $this->views->forStation($lobby, $this->lobbies->now()),
        ]);
    }

    public function heartbeat(string $code): JsonResponse
    {
        $this->lobbies->heartbeat($code);
        $this->lobbies->tick($code);

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->leave($code, $this->id($request)), redirect()->route('home'));
    }

    public function ready(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->setReady($code, $this->id($request), $request->boolean('ready')));
    }

    public function preset(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->setPreset($code, $this->id($request), (string) $request->input('preset')));
    }

    public function custom(Request $request, string $code): RedirectResponse
    {
        $values = $request->input('values');

        return $this->attempt(fn () => $this->lobbies->setCustom($code, $this->id($request), is_array($values) ? $values : []));
    }

    public function start(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->start($code, $this->id($request)));
    }

    public function kick(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->kick($code, $this->id($request), (string) $request->input('player')));
    }

    public function host(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->makeHost($code, $this->id($request), (string) $request->input('player')));
    }

    public function resetWins(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->resetWins($code, $this->id($request)));
    }

    public function back(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->backToLobby($code, $this->id($request)));
    }

    public function pause(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->pause($code, $this->id($request)));
    }

    public function resume(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->resume($code, $this->id($request)));
    }

    public function end(Request $request, string $code): RedirectResponse
    {
        return $this->attempt(fn () => $this->lobbies->endGame($code, $this->id($request)));
    }

    /**
     * A move in the game: ready, pick, escort, override, cord, nominate,
     * vote or accuse.
     */
    public function act(Request $request, string $code): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:ready,pick,escort,override,cord,nominate,vote,accuse'],
            'card' => ['nullable', 'string', 'max:16'],
            'decision' => ['nullable', 'string', 'in:allow,veto'],
            'nominee' => ['nullable', 'string', 'max:32'],
            'suspect' => ['nullable', 'string', 'max:32'],
            'yes' => ['nullable', 'boolean'],
            'ready' => ['nullable', 'boolean'],
        ]);
        $payload = array_filter([
            'card' => $validated['card'] ?? null,
            'decision' => $validated['decision'] ?? null,
            'nominee' => $validated['nominee'] ?? null,
            'suspect' => $validated['suspect'] ?? null,
            'yes' => $request->has('yes') ? $request->boolean('yes') : null,
            'ready' => $request->has('ready') ? $request->boolean('ready') : null,
        ], fn ($v) => $v !== null);

        return $this->attempt(fn () => $this->lobbies->act($code, $this->id($request), $validated['action'], $payload));
    }

    private function id(Request $request): string
    {
        return (string) Identity::id($request);
    }

    private function attempt(Closure $action, ?RedirectResponse $then = null): RedirectResponse
    {
        try {
            $action();
        } catch (LobbyError|RuleViolation $e) {
            return back()->withErrors(['game' => $e->getMessage()]);
        }

        return $then ?? back();
    }
}
