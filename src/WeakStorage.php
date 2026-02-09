<?php

declare(strict_types=1);

namespace Ewn;

use ArrayAccess;
use ArrayIterator;
use Countable;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use Override;
use ReflectionClass;
use Traversable;
use WeakMap;
use WeakReference;

/**
 * Object for caching other objects.
 *
 * @template TKey of string|int
 * @template TValue of object
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, WeakReference<TValue>>
 */
final class WeakStorage implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * Send to **Generator** to remove current object.
     */
    public const int REMOVE_OBJECT = 1;

    /**
     * Total amount of WeakReferences in the object.
     */
    public int $size {
        get {
            return count($this->storage);
        }
    }

    /**
     * The array that stores all **WeakReferences**.
     *
     * @var array<TKey, WeakReference<TValue>>
     */
    private array $storage = [];

    /**
     * @var WeakMap<TValue, array<int|string, mixed>>
     */
    private WeakMap $weakMap;

    public function __construct()
    {
        $this->weakMap = new WeakMap;
    }

    /**
     * @param  TKey  $name  Property name.
     * @return null|TValue
     */
    public function __get(string|int $name): ?object
    {
        return $this->get($name);
    }

    /**
     * @param  TKey  $name  Property name.
     * @param  TValue  $value  Object to cache.
     */
    public function __set(string|int $name, object $value): void
    {
        $this->set($name, $value);
    }

    /**
     * @param TKey $name Property name.
     * @return bool If property is set.
     */
    public function __isset(string|int $name): bool
    {
        return isset($this->storage[$name]);
    }

    /**
     * @param TKey $name Property to unset.
     * @return void
     */
    public function __unset(string|int $name): void
    {
        unset($this->storage[$name]);
    }

    /**
     * This method is called by {@see \var_dump() var_dump()} when dumping an object to get the properties that should be shown.
     *
     * @return array<string|int, WeakReference<TValue>>
     */
    public function __debugInfo(): array
    {
        return $this->storage;
    }

    /**
     * Get object from cache if it exists.
     *
     * @param  TKey  $ident
     * @return null|TValue
     */
    public function get(string|int $ident): ?object
    {
        $ref = ($this->storage[$ident] ?? null)?->get();

        if ($ref === null && $this->exists($ident)) {
            unset($this->storage[$ident]);
        }

        return $ref;
    }

    /**
     * Gets the full **WeakReference** form an identifier.
     *
     * @param TKey $ident
     * @return null|WeakReference<TValue> The **WeakReference** or null if it does not exits.
     */
    public function getWeak(string|int $ident): ?WeakReference
    {
        return $this->storage[$ident] ?? null;
    }

    /**
     * Get data array associated with an identifier or object.
     *
     * @param  TValue|TKey  $value  Identifier or object.
     * @return array<string|int, mixed>
     */
    public function getData(object|string|int $value): array
    {
        if (! is_object($value)) {
            $value = $this->get($value);
        }

        return $this->weakMap[$value] ?? [];
    }

    /**
     * Add object to cache.
     *
     * @param  TKey  $ident  Identifier for an object.
     * @param  TValue  $object  Object to cache.
     * @param array<int|string, mixed> $data
     */
    public function set(string|int $ident, object $object, ?array $data = null): void
    {
        $this->storage[$ident] = WeakReference::create($object);

        if ($data !== null) {
            $this->weakMap[$object] = $data;
        }
    }

    /**
     * Removes an object from the storage and returns it.
     * 
     * @param  TKey  $ident
     * @return null|TValue Removed object or null.
     */
    public function remove(string|int $ident): ?object
    {
        if (! isset($this->storage[$ident])) {
            return null;
        }

        $object = $this->get($ident);
        unset($this->storage[$ident]);

        return $object;
    }

    /**
     * Clear all removed objects.
     */
    public function clean(): void
    {
        foreach ($this->storage as $ident => $WeakReference) {
            if ($WeakReference->get() === null) {
                unset($this->storage[$ident]);
            }
        }
    }

    /**
     * Check if there is a **TValue** linked to the **TKey** regardless of cached object status.
     *
     * @param  TKey  $ident
     */
    public function exists(string|int $ident): bool
    {
        return isset($this->storage[$ident]);
    }

    /**
     * Check if a cached object is still valid.
     *
     * @param  TKey  $ident
     */
    public function valid(string|int $ident): bool
    {
        return ($this->storage[$ident] ?? null)?->get() !== null;
    }

    /**
     * @return Generator<TKey, null|TValue>
     */
    public function createGenerator(): Generator
    {
        foreach ($this->storage as $ident => $weakRef) {
            $return = yield $ident => $weakRef->get();

            switch ($return) {
                case self::REMOVE_OBJECT:
                    $this->remove($ident);
                    break;
                default:
                    break;
            }
        }
    }
    
    /**
     * Whether a offset exists
     *
     * @param TKey $offset
     * @return boolean
     */
    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return $this->exists($offset);
    }

    /**
     * Offset to retrieve
     *
     * @param TKey $offset
     * @return TValue|null
     */
    #[Override]
    public function offsetGet(mixed $offset): ?object
    {
        return $this->get($offset);
    }

    /**
     * Offset to set
     *
     * @param TKey|null $offset
     * @param TValue $value
     * @return void
     * @throws InvalidArgumentException if $offset is null.
     */
    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new InvalidArgumentException('$offset cannot be null');
        }
        $this->set($offset, $value);
    }

    /**
     * Offset to unset
     *
     * @param TKey $offset
     * @return void
     */
    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        unset($this->storage[$offset]);
    }
    
    /**
     * Counts the amount of WeakReferences that still contains a reference.
     *
     * @return int<0, max> Sum of references.
     */
    #[Override]
    public function count(): int
    {
        $count = 0;
        foreach ($this->storage as $weakRef) {
            if ($weakRef->get() !== null) {
                $count++;
            }
        }

        return $count;
    }
    
    /**
     * Retrieve an external iterator.
     *
     * @return Traversable<TKey, WeakReference<TValue>>
     */
    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->storage);
    }
}
