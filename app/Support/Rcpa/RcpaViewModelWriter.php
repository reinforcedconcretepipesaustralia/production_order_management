<?php

namespace App\Support\Rcpa;

use Illuminate\Support\Str;
use InvalidArgumentException;

class RcpaViewModelWriter
{
    /**
     * @param  list<array{name: string, type_name?: string, auto_increment?: bool}>  $columns
     */
    public function contents(string $view, array $columns, string $primaryKey): string
    {
        $class = $this->className($view);
        $table = $this->tableName($view);

        if (! collect($columns)->contains(
            fn (array $column): bool => ($column['name'] ?? null) === $primaryKey
        )) {
            throw new InvalidArgumentException("Column [{$primaryKey}] does not exist on view [{$view}].");
        }

        $incrementing = $this->incrementing($columns, $primaryKey) ? 'true' : 'false';
        $casts = $this->casts($columns);

        $castsBlock = $casts === []
            ? ''
            : $this->castsMethod($casts);

        return <<<PHP
<?php

namespace App\\Models\\Rcpa;

class {$class} extends ReadOnlyView
{
    protected \$table = '{$table}';

    protected \$primaryKey = '{$primaryKey}';

    public \$incrementing = {$incrementing};
{$castsBlock}}

PHP;
    }

    public function className(string $view): string
    {
        $name = Str::afterLast($view, '.');
        $class = Str::studly($name);

        return str_ends_with($class, 'View') ? $class : $class.'View';
    }

    public function path(string $view): string
    {
        return app_path('Models/Rcpa/'.$this->className($view).'.php');
    }

    public function tableName(string $view): string
    {
        return str_contains($view, '.') ? $view : $view;
    }

    /**
     * @param  list<array{name: string, auto_increment?: bool}>  $columns
     */
    public function incrementing(array $columns, string $primaryKey): bool
    {
        foreach ($columns as $column) {
            if ((string) ($column['name'] ?? '') === $primaryKey) {
                return (bool) ($column['auto_increment'] ?? false);
            }
        }

        return false;
    }

    /**
     * @param  list<array{name: string, type_name?: string}>  $columns
     * @return array<string, string>
     */
    public function casts(array $columns): array
    {
        $casts = [];

        foreach ($columns as $column) {
            $name = (string) ($column['name'] ?? '');
            $type = strtolower((string) ($column['type_name'] ?? ''));

            $cast = match (true) {
                in_array($type, ['datetime', 'datetime2', 'smalldatetime'], true) => 'datetime',
                $type === 'date' => 'date',
                in_array($type, ['bit', 'boolean'], true) => 'boolean',
                in_array($type, ['decimal', 'numeric', 'money', 'smallmoney'], true) => 'decimal:2',
                default => null,
            };

            if ($name !== '' && $cast !== null) {
                $casts[$name] = $cast;
            }
        }

        return $casts;
    }

    /**
     * @param  array<string, string>  $casts
     */
    private function castsMethod(array $casts): string
    {
        $lines = [];

        foreach ($casts as $name => $cast) {
            $lines[] = "            '{$name}' => '{$cast}',";
        }

        $body = implode("\n", $lines);

        return <<<PHP

    protected function casts(): array
    {
        return [
{$body}
        ];
    }

PHP;
    }
}
