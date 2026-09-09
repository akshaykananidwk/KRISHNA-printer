<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small service container with constructor autowiring. Services are singletons
 * per request, which is what we want: one PDO connection, one settings cache.
 */
final class Container
{
    private static ?Container $instance = null;

    /** @var array<string,callable> */
    private array $factories = [];

    /** @var array<string,object> */
    private array $instances = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    /** @template T of object @param class-string<T>|string $id @return T|object */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        return $this->instances[$id] = $this->build($id);
    }

    /** Build a fresh instance without caching it. */
    public function make(string $id): object
    {
        if (isset($this->factories[$id])) {
            return ($this->factories[$id])($this);
        }
        return $this->build($id);
    }

    private function build(string $id): object
    {
        if (!class_exists($id)) {
            throw new \RuntimeException('Cannot resolve service: ' . $id);
        }
        $reflection = new \ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException('Service is not instantiable: ' . $id);
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }
            if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                $args[] = null;
                continue;
            }
            throw new \RuntimeException(sprintf(
                'Cannot autowire parameter $%s of %s',
                $param->getName(),
                $id
            ));
        }
        return $reflection->newInstanceArgs($args);
    }
}
