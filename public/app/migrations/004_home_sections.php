<?php
declare(strict_types=1);

// Starter homepage, as in the approved wireframe. Every section is editable in the admin later.
return function (PDO $db, string $driver): void {
    $ins = $db->prepare('INSERT INTO home_sections (position, layout, title, span, settings) VALUES (?, ?, ?, ?, ?)');
    $pos = 0;
    foreach ([
        ['hero_grid', '', 8, ['count' => 4]],
        ['list', '', 4, ['count' => 5, 'tabs' => true]],
        ['overlay_list', 'Asia', 6, ['categories' => ['asia'], 'count' => 4]],
        ['overlay_list', 'Middle East and Africa', 6, ['categories' => ['middle-east-africa'], 'count' => 4]],
        ['lead_grid', 'Europe', 12, ['categories' => ['europe'], 'count' => 5]],
        ['grid', 'Land', 12, ['categories' => ['land'], 'count' => 4, 'columns' => 4]],
        ['lead_list', 'Air', 8, ['categories' => ['air'], 'count' => 5]],
        ['headlines', 'Most read', 4, ['count' => 5, 'order_by' => 'views', 'avoid_duplicates' => false]],
    ] as [$layout, $title, $span, $settings]) {
        $ins->execute([$pos++, $layout, $title, $span, json_encode($settings + ['avoid_duplicates' => true])]);
    }
};
