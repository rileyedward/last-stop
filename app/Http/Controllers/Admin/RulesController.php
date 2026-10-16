<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminDefaults;
use App\Game\Data\Departments;
use App\Game\Data\GameData;
use App\Game\Data\SettingsSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Every rule default, the per-player-count table, presets and timers.
 */
class RulesController extends Controller
{
    public function __construct(private readonly AdminDefaults $defaults) {}

    public function show(GameData $data): Response
    {
        $fields = array_values(array_filter(
            SettingsSchema::forScreens(),
            fn (array $f) => ! in_array($f['key'], GameData::TABLE_KEYS, true),
        ));

        return Inertia::render('admin/Rules', [
            'fields' => $fields,
            'tableFields' => array_values(array_filter(
                SettingsSchema::forScreens(),
                fn (array $f) => in_array($f['key'], GameData::TABLE_KEYS, true),
            )),
            'values' => array_intersect_key($data->settings, array_flip(array_column($fields, 'key'))),
            'table' => array_map(fn (int $players, array $row) => [
                'players' => $players,
                'departments' => array_map(fn (string $d) => Departments::NAMES[$d], $row['departments']),
                ...array_intersect_key($row, array_flip(GameData::TABLE_KEYS)),
            ], array_keys($data->table), $data->table),
            'presets' => ['easy' => $data->presets['easy'] ?? 1, 'hard' => $data->presets['hard'] ?? -1],
            'edited' => array_values(array_intersect($this->defaults->edited(), AdminDefaults::SETTING_SOURCES)),
        ]);
    }

    public function update(Request $request, GameData $data): RedirectResponse
    {
        $values = $request->input('values');
        $table = $request->input('table');
        $presets = $request->input('presets');
        $values = is_array($values) ? $values : [];
        $table = is_array($table) ? $table : [];
        $presets = is_array($presets) ? $presets : [];

        $errors = SettingsSchema::errors(array_diff_key($values, array_flip(GameData::TABLE_KEYS)));
        foreach ($table as $i => $row) {
            $rowErrors = SettingsSchema::errors(array_intersect_key(is_array($row) ? $row : [], array_flip(GameData::TABLE_KEYS)));
            foreach ($rowErrors as $key => $message) {
                $errors["table.{$i}.{$key}"] = $message;
            }
        }
        foreach (['easy', 'hard'] as $preset) {
            $offset = $presets[$preset] ?? 0;
            if (! is_numeric($offset) || (int) $offset < -5 || (int) $offset > 5) {
                $errors["presets.{$preset}"] = ucfirst($preset).' must shift the days by -5 to 5.';
            }
        }
        if ($errors !== []) {
            return back()->withErrors($errors);
        }

        $rows = $data->rows;
        $sources = [
            'settings' => $this->withValues($rows['settings'] ?? [], $values),
            'timers' => $this->withValues($rows['timers'] ?? [], $values),
            'players' => array_map(function (array $row) use ($table) {
                foreach ($table as $edit) {
                    if (is_array($edit) && (string) ($edit['players'] ?? '') === ($row['players'] ?? '')) {
                        foreach (GameData::TABLE_KEYS as $key) {
                            if (isset($edit[$key]) && is_numeric($edit[$key])) {
                                $row[$key] = (string) (int) $edit[$key];
                            }
                        }
                    }
                }

                return $row;
            }, $rows['players'] ?? []),
            'presets' => [
                ['preset' => 'easy', 'days_offset' => (string) (int) ($presets['easy'] ?? 1), 'notes' => 'Days added for Easy'],
                ['preset' => 'standard', 'days_offset' => '0', 'notes' => 'players.csv as written'],
                ['preset' => 'hard', 'days_offset' => (string) (int) ($presets['hard'] ?? -1), 'notes' => 'Days added for Hard'],
            ],
        ];

        return $this->store($sources);
    }

    public function reset(): RedirectResponse
    {
        $this->defaults->reset(AdminDefaults::SETTING_SOURCES);

        return redirect()->route('admin.rules')->with('status', 'Rules are back to the shipped defaults.');
    }

    /**
     * Store only what changed; a source back at its shipped rows is reset.
     *
     * @param  array<string, list<array<string, string>>>  $sources
     */
    private function store(array $sources): RedirectResponse
    {
        $shipped = GameData::shipped()->rows;
        $save = [];
        $reset = [];
        foreach ($sources as $source => $rows) {
            if ($this->same($rows, $shipped[$source] ?? [], $source)) {
                $reset[] = $source;
            } else {
                $save[$source] = $rows;
            }
        }

        try {
            if ($save !== []) {
                $this->defaults->save($save);
            }
            if ($reset !== []) {
                $this->defaults->reset($reset);
            }
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['rules' => $e->getMessage()]);
        }

        return redirect()->route('admin.rules')->with('status', 'Saved. New lobbies use these defaults.');
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<mixed>  $values
     * @return list<array<string, string>>
     */
    private function withValues(array $rows, array $values): array
    {
        return array_map(function (array $row) use ($values) {
            $key = $row['setting'] ?? '';
            if (array_key_exists($key, $values) && is_numeric($values[$key])) {
                $row['value'] = (string) (int) $values[$key];
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  list<array<string, string>>  $a
     * @param  list<array<string, string>>  $b
     */
    private function same(array $a, array $b, string $source): bool
    {
        $key = $source === 'presets' ? 'days_offset' : null;
        $norm = fn (array $rows) => array_map(fn (array $r) => $key === null ? $r : [$r['preset'] ?? '', $r[$key] ?? ''], $rows);

        return $norm($a) == $norm($b);
    }
}
