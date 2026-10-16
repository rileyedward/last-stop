<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminDefaults;
use App\Game\Data\Card;
use App\Game\Data\GameData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The task and weather decks: list them, import a CSV in the simulator's
 * format, export the current deck, or go back to the shipped one.
 */
class CardsController extends Controller
{
    /** Columns an imported deck must have; the rest default to blank or 0. */
    public const REQUIRED = [
        'tasks' => ['department', 'card'],
        'weather' => ['card'],
    ];

    public function __construct(private readonly AdminDefaults $defaults) {}

    public function show(GameData $data): Response
    {
        $card = fn (Card $c) => $c->toArray();
        $tasks = [];
        foreach ($data->tasks as $deck) {
            array_push($tasks, ...array_map($card, $deck));
        }

        return Inertia::render('admin/Cards', [
            'tasks' => $tasks,
            'weather' => array_map($card, $data->weather),
            'columns' => array_map(fn (array $s) => $s['columns'], array_intersect_key(GameData::SOURCES, array_flip(AdminDefaults::DECKS))),
            'edited' => array_values(array_intersect($this->defaults->edited(), AdminDefaults::DECKS)),
        ]);
    }

    public function import(Request $request, string $deck): RedirectResponse
    {
        $this->mustBeDeck($deck);
        $request->validate([
            'csv' => ['required', 'file', 'max:200', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel'],
        ], [
            'csv.mimetypes' => 'Upload a .csv file.',
            'csv.max' => 'That file is too big for a deck of cards.',
        ]);

        $file = $request->file('csv');
        $text = $file !== null && ! is_array($file) ? (string) file_get_contents($file->getRealPath()) : '';
        $rows = GameData::parseCsv($text);
        if ($rows === []) {
            return back()->withErrors(['csv' => 'That file has no cards in it.']);
        }

        $missing = array_diff(self::REQUIRED[$deck], array_keys($rows[0]));
        if ($missing !== []) {
            return back()->withErrors(['csv' => 'The file needs a '.implode(' and a ', $missing).' column. Export the current deck to see the format.']);
        }

        $columns = GameData::SOURCES[$deck]['columns'];
        $rows = array_map(fn (array $row) => array_combine($columns, array_map(fn (string $c) => $row[$c] ?? '', $columns)), $rows);

        try {
            $this->defaults->save([$deck => $rows]);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['csv' => $e->getMessage()]);
        }

        return redirect()->route('admin.cards')->with('status', 'Imported '.count($rows).' '.($deck === 'tasks' ? 'task' : 'weather').' cards. New games use them.');
    }

    public function export(GameData $data, string $deck): HttpResponse
    {
        $this->mustBeDeck($deck);

        return response($data->csv($deck), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$deck}.csv\"",
        ]);
    }

    public function reset(string $deck): RedirectResponse
    {
        $this->mustBeDeck($deck);
        $this->defaults->reset([$deck]);

        return redirect()->route('admin.cards')->with('status', 'The '.($deck === 'tasks' ? 'task' : 'weather').' deck is back to the shipped cards.');
    }

    private function mustBeDeck(string $deck): void
    {
        abort_unless(in_array($deck, AdminDefaults::DECKS, true), 404);
    }
}
