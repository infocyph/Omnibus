<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Routing;

final class RouteMap
{
    /** @var array<class-string, Route> */
    private array $resolved = [];

    /** @param array<class-string, Route> $routes */
    public function __construct(private array $routes = [], private readonly Route $default = new Route())
    {
        foreach ($routes as $type => $route) {
            self::validateMapping($type, $route);
        }
    }

    public function for(object $message): Route
    {
        $class = $message::class;
        if (isset($this->resolved[$class])) {
            return $this->resolved[$class];
        }
        if (isset($this->routes[$class])) {
            return $this->resolved[$class] = $this->routes[$class];
        }

        foreach (class_parents($message) as $type) {
            if (isset($this->routes[$type])) {
                return $this->resolved[$class] = $this->routes[$type];
            }
        }

        $matched = $this->interfaceRoutes($message);
        if ($matched !== []) {
            $route = $matched[array_key_first($matched)];
            foreach ($matched as $candidate) {
                if ($candidate != $route) {
                    throw new AmbiguousRoute(sprintf(
                        'Multiple mapped interfaces provide conflicting routes for "%s".',
                        $class,
                    ));
                }
            }

            return $this->resolved[$class] = $route;
        }

        return $this->resolved[$class] = $this->default;
    }

    private static function validateMapping(mixed $type, mixed $route): void
    {
        if (
            !is_string($type)
            || (!class_exists($type) && !interface_exists($type))
            || !$route instanceof Route
        ) {
            throw new \InvalidArgumentException('Route mappings require loadable types and Route values.');
        }
    }

    /** @return array<class-string, Route> */
    private function interfaceRoutes(object $message): array
    {
        $matched = [];
        $interfaces = class_implements($message);
        sort($interfaces);
        foreach ($interfaces as $type) {
            if (isset($this->routes[$type])) {
                $matched[$type] = $this->routes[$type];
            }
        }

        return $matched;
    }
}
