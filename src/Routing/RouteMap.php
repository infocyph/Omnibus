<?php

declare(strict_types=1);

namespace Infocyph\Omnibus\Routing;

final class RouteMap
{
    /** @var array<class-string, Route> */
    private array $resolved = [];

    /** @param array<class-string, Route> $routes */
    public function __construct(private array $routes = [], private readonly Route $default = new Route()) {}

    public function for(object $message): Route
    {
        $class = $message::class;
        if (isset($this->resolved[$class])) {
            return $this->resolved[$class];
        }
        if (isset($this->routes[$class])) {
            return $this->resolved[$class] = $this->routes[$class];
        }

        foreach (class_parents($message) + class_implements($message) as $type) {
            if (isset($this->routes[$type])) {
                return $this->resolved[$class] = $this->routes[$type];
            }
        }

        return $this->resolved[$class] = $this->default;
    }
}
