<?php

declare(strict_types=1);

test('real-world examples reference loadable Omnibus types and every module', function (): void {
    $path = dirname(__DIR__, 2).'/docs/recipes.rst';
    $document = file_get_contents($path);
    if (!is_string($document)) {
        throw new RuntimeException('Unable to read the real-world examples.');
    }

    preg_match_all(
        '/^\s+use (Infocyph\\\\Omnibus\\\\[^;]+);$/m',
        $document,
        $matches,
    );
    $types = array_values(array_unique($matches[1] ?? []));

    expect($types)->not->toBe([]);
    foreach ($types as $type) {
        expect(
            class_exists($type)
            || interface_exists($type)
            || enum_exists($type),
            sprintf('Documentation type %s must remain loadable.', $type),
        )->toBeTrue();
    }

    foreach ([
        'Broadcasting',
        'Clock',
        'Consumer',
        'Dispatch',
        'Envelope',
        'Event',
        'Failure',
        'Handler',
        'Integration',
        'Retry',
        'Routing',
        'Scheduling',
        'Serialization',
        'Telemetry',
        'Testing',
        'Transport',
        'Workflow',
    ] as $module) {
        expect($document)->toContain('Infocyph\\Omnibus\\'.$module.'\\');
    }
});
