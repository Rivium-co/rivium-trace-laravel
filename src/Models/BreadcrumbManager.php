<?php

namespace RiviumTrace\Laravel\Models;

class BreadcrumbManager
{
    private array $items = [];
    private int $limit;

    public function __construct(int $max = 50)
    {
        $this->limit = max(0, min(100, $max));
    }

    public function add(array|Breadcrumb $crumb): void
    {
        if ($this->limit === 0) {
            return;
        }

        if (! $crumb instanceof Breadcrumb) {
            $crumb = new Breadcrumb($crumb);
        }

        $this->items[] = $crumb;

        if (count($this->items) > $this->limit) {
            array_shift($this->items);
        }
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function getAll(): array
    {
        return $this->items;
    }

    public function getRecent(int $n = 10): array
    {
        $slice = array_slice($this->items, -$n);
        return array_map(fn (Breadcrumb $b) => $b->toArray(), $slice);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function toArray(): array
    {
        return array_map(fn (Breadcrumb $b) => $b->toArray(), $this->items);
    }
}
