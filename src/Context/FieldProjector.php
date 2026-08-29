<?php

declare(strict_types=1);

namespace Fissible\Verdict\Context;

use InvalidArgumentException;

final class FieldProjector
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public function project(array $payload, array $paths): array
    {
        if ($paths === []) {
            throw new InvalidArgumentException('A context release must explicitly allow at least one field path.');
        }

        $projected = [];

        foreach ($paths as $path) {
            $segments = explode('.', $path);

            if (trim($path) === '' || in_array('', $segments, true)) {
                throw new InvalidArgumentException("The context field path [{$path}] is invalid.");
            }

            [$found, $selection] = $this->select($payload, $segments);

            if ($found && is_array($selection)) {
                $projected = $this->merge($projected, $selection);
            }
        }

        $this->assertScalarLeaves($projected);

        return $projected;
    }

    /**
     * @param  array<array-key, mixed>  $projected
     */
    private function assertScalarLeaves(array $projected, string $path = ''): void
    {
        foreach ($projected as $key => $value) {
            $leafPath = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($value)) {
                $this->assertScalarLeaves($value, $leafPath);

                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("The context release field [{$leafPath}] has unsupported type [".get_debug_type($value).']; a context release can only project scalar values.');
            }
        }
    }

    /**
     * @param  list<string>  $segments
     * @return array{bool, mixed}
     */
    private function select(mixed $value, array $segments): array
    {
        if ($segments === []) {
            return [true, $value];
        }

        if (! is_array($value)) {
            return [false, null];
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            $selection = [];
            $found = false;

            foreach ($value as $key => $item) {
                [$itemFound, $selected] = $this->select($item, $segments);

                if ($itemFound) {
                    $selection[$key] = $selected;
                    $found = true;
                }
            }

            return [$found, $selection];
        }

        if (! array_key_exists($segment, $value)) {
            return [false, null];
        }

        [$found, $selected] = $this->select($value[$segment], $segments);

        return $found ? [true, [$segment => $selected]] : [false, null];
    }

    /**
     * @param  array<array-key, mixed>  $left
     * @param  array<array-key, mixed>  $right
     * @return array<array-key, mixed>
     */
    private function merge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (isset($left[$key]) && is_array($left[$key]) && is_array($value)) {
                $left[$key] = $this->merge($left[$key], $value);

                continue;
            }

            $left[$key] = $value;
        }

        return $left;
    }
}
